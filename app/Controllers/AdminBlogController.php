<?php

namespace App\Controllers;

use App\Core\Http;
use App\Core\Security;
use App\Models\Blog;

class AdminBlogController
{
    private $admin;

    private function audit(string $action, string $detail): void
    { 
        db()->run('INSERT INTO ' . db()->table('admin_logs') . '(admin_id,action,detail,created_at) VALUES (?,?,?,UTC_TIMESTAMP())', [$this->admin['id'], $action, $detail]);
    }

    public function handle(array $admin, string $method, array $sections, array $superOnly): void
    {
        $this->admin = $admin;
        // Повторная проверка защищает и при отдельном подключении контроллера.
        if ($admin['role'] !== 'super') {
            http_response_code(403);
            echo e(t('forbidden'));
            return;
        }
        $error = '';
        $failed = null;
        if ($method === 'POST') {
            Http::csrf();
            if (!Security::hit('blog-action:' . $admin['id'], 60, 60)) {
                Http::json(['error' => t('rate')], 429);
            }
            try {
                $action = (string) ($_POST['action'] ?? '');
                if ($action === 'logout') {
                    $this->audit('logout', '');
                    unset($_SESSION['admin_id'], $_SESSION['pending_totp']);
                    session_regenerate_id(true);
                    Http::redirect('admin');
                }
                if ($action === 'install') {
                    Blog::migrate();
                    $this->audit('blog_install', 'schema:1');
                    $_SESSION['admin_flash'] = 'Блог включён. Можно добавлять записи.';
                    Http::redirect('admin/blog');
                }
                if (!Blog::ready()) {
                    throw new \InvalidArgumentException('Сначала включите блог.');
                }
                if ($action === 'upload') {
                    $this->upload();
                } elseif ($action === 'save') {
                    $id = Blog::save($_POST, (int) $admin['id']);
                    $this->audit('blog_save', 'post:' . $id . ' status:' . ($_POST['status'] ?? 'draft'));
                    $_SESSION['admin_flash'] = t('saved');
                    Http::redirect('admin/blog?id=' . $id);
                } elseif ($action === 'delete') {
                    $id = (int) ($_POST['id'] ?? 0);
                    db()->transaction(function () use ($id) {
                        $post = db()->one('SELECT revision FROM ' . db()->table('blog_posts') . ' WHERE id=? FOR UPDATE', [$id]);
                        if (!$post || (int) $post['revision'] !== (int) ($_POST['revision'] ?? 0)) {
                            throw new \InvalidArgumentException('Запись изменена или удалена. Обновите страницу.');
                        }
                        db()->run('DELETE FROM ' . db()->table('blog_posts') . ' WHERE id=?', [$id]);
                        $this->audit('blog_delete', 'post:' . $id);
                    });
                    $_SESSION['admin_flash'] = 'Запись удалена.';
                    Http::redirect('admin/blog');
                } elseif ($action === 'cleanup_media') {
                    $count = $this->cleanupMedia();
                    $this->audit('blog_media_cleanup', 'files:' . $count);
                    $_SESSION['admin_flash'] = 'Удалено неиспользуемых картинок: ' . $count;
                    Http::redirect('admin/blog');
                } else {
                    throw new \InvalidArgumentException(t('invalid'));
                }
            } catch (\InvalidArgumentException $exception) {
                if (($_POST['action'] ?? '') === 'upload') {
                    Http::json(['error' => $exception->getMessage()], 422);
                }
                $error = $exception->getMessage();
                if (($_POST['action'] ?? '') === 'save') {
                    $failed = $_POST;
                }
            }
        } elseif ($method !== 'GET') {
            http_response_code(405);
            return;
        }
        $ready = Blog::ready();
        $page = min(10000, max(1, (int) ($_GET['page'] ?? 1)));
        $query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
        $rows = [];
        $edit = null;
        $total = 0;
        if ($ready) {
            $search = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
            $total = (int) db()->one('SELECT COUNT(*) n FROM ' . db()->table('blog_posts') . ' WHERE title LIKE ?', [$search])['n'];
            $rows = db()->all('SELECT id,title,slug,language,status,revision,published_at,updated_at FROM ' . db()->table('blog_posts') . ' WHERE title LIKE ? ORDER BY id DESC LIMIT 20 OFFSET ' . (($page - 1) * 20), [$search]);
            if (isset($_GET['id'])) {
                $edit = db()->one('SELECT * FROM ' . db()->table('blog_posts') . ' WHERE id=?', [(int) $_GET['id']]);
                if (!$edit && !$failed) {
                    http_response_code(404);
                    $error = 'Запись не найдена.';
                }
            }
        }
        $edit = $failed ?? $edit;
        Http::view('admin', ['section' => 'blog', 'admin' => $admin, 'sections' => $sections, 'superOnly' => $superOnly, 'error' => $error, 'rows' => $rows, 'pageNumber' => $page, 'query' => $query, 'blogReady' => $ready, 'blogEdit' => $edit, 'blogShowEditor' => $edit !== null || isset($_GET['new']), 'blogTotal' => $total]);
    }

    private function upload(): void
    {
        if (!Security::hit('blog-upload:' . $this->admin['id'], 20, 60)) {
            Http::json(['error' => t('rate')], 429);
        }
        $file = $_FILES['image'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new \InvalidArgumentException('Не удалось загрузить картинку. Проверьте лимиты загрузки PHP.');
        }
        $size = filesize($file['tmp_name']);
        if ($size < 1 || $size > 5 * 1024 * 1024) {
            throw new \InvalidArgumentException('Картинка должна быть не больше 5 МБ.');
        }
        $info = @getimagesize($file['tmp_name']);
        $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!$info || !isset($types[$info['mime'] ?? '']) || $info[0] < 1 || $info[1] < 1 || $info[0] > 6000 || $info[1] > 6000 || $info[0] * $info[1] > 20000000) {
            throw new \InvalidArgumentException('Нужна JPG, PNG, WebP или GIF: до 6000 px по стороне и 20 мегапикселей. SVG и другие файлы запрещены.');
        }
        $directory = ROOT . '/storage/blog';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \InvalidArgumentException('Не удалось создать storage/blog. Проверьте права.');
        }
        if (!is_writable($directory)) {
            throw new \InvalidArgumentException('Папка storage/blog недоступна для записи.');
        }
        $filename = bin2hex(random_bytes(24)) . '.' . $types[$info['mime']];
        $target = $directory . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new \InvalidArgumentException('Не удалось сохранить картинку.');
        }
        @chmod($target, 0600);
        try {
            db()->run('INSERT INTO ' . db()->table('blog_media') . '(admin_id,filename,mime,size,created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())', [$this->admin['id'], $filename, $info['mime'], $size]);
        } catch (\Throwable $exception) {
            @unlink($target);
            throw $exception;
        }
        $this->audit('blog_upload', 'file:' . $filename);
        Http::json(['ok' => true, 'filename' => $filename, 'url' => url('blog/image/' . $filename)]);
    }

    private function cleanupMedia(): int
    {
        $rows = db()->all('SELECT m.id,m.filename FROM ' . db()->table('blog_media') . ' m LEFT JOIN ' . db()->table('blog_post_media') . " pm ON pm.media_id=m.id WHERE pm.media_id IS NULL AND m.created_at<UTC_TIMESTAMP()-INTERVAL 1 DAY LIMIT 200");
        $count = 0;
        foreach ($rows as $row) {
            // Блокировка media согласуется с сохранением записей и защищает от гонок.
            db()->transaction(function () use ($row, &$count) {
                $media = db()->one('SELECT filename FROM ' . db()->table('blog_media') . ' WHERE id=? FOR UPDATE', [$row['id']]);
                if (!$media || db()->one('SELECT post_id FROM ' . db()->table('blog_post_media') . ' WHERE media_id=? LIMIT 1', [$row['id']])) {
                    return;
                }
                $file = ROOT . '/storage/blog/' . $media['filename'];
                if (!is_file($file) || @unlink($file)) {
                    db()->run('DELETE FROM ' . db()->table('blog_media') . ' WHERE id=?', [$row['id']]);
                    $count++;
                }
            });
        }
        return $count;
    }
}
