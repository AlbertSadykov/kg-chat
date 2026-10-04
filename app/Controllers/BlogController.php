<?php

namespace App\Controllers;

use App\Core\Http;
use App\Models\Blog;
use App\Blog\Content;

class BlogController
{
    public function handle(string $path): void 
    {
        if (strpos($path, '/blog/image/') === 0) {
            $this->image(substr($path, strlen('/blog/image/')));
            return;
        }
        if ($path === '/blog') {
            $page = min(10000, max(1, (int) ($_GET['page'] ?? 1)));
            $rows = Blog::latest(10, ($page - 1) * 9, lang());
            $hasNext = count($rows) > 9;
            Http::view('blog', ['posts' => array_slice($rows, 0, 9), 'pageNumber' => $page, 'hasNext' => $hasNext]);
            return;
        }
        $slug = substr($path, strlen('/blog/'));
        $post = Blog::ready() && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)
            ? db()->one('SELECT * FROM ' . db()->table('blog_posts') . " WHERE slug=? AND language=? AND status='published' AND published_at<=UTC_TIMESTAMP()", [$slug, lang()])
            : null;
        if (!$post) {
            http_response_code(404);
            Http::view('page', ['page' => '404']);
            return;
        }
        Http::view('blog-post', ['post' => $post, 'bodyHtml' => Content::html($post['content_json'])]);
    }

    private function image(string $filename): void
    {
        if (Content::filename($filename) !== $filename || !Blog::ready()) {
            http_response_code(404);
            return;
        }
        $admin = !empty($_SESSION['admin_id']) && time() - (int) ($_SESSION['admin_at'] ?? 0) <= 1800
            ? db()->one('SELECT id FROM ' . db()->table('admins') . " WHERE id=? AND enabled=1 AND role='super'", [$_SESSION['admin_id']])
            : null;
        if ($admin) {
            $row = db()->one('SELECT * FROM ' . db()->table('blog_media') . ' WHERE filename=?', [$filename]);
        } else {
            $row = db()->one('SELECT m.* FROM ' . db()->table('blog_media') . ' m JOIN ' . db()->table('blog_post_media') . ' pm ON pm.media_id=m.id JOIN ' . db()->table('blog_posts') . " p ON p.id=pm.post_id WHERE m.filename=? AND p.language=? AND p.status='published' AND p.published_at<=UTC_TIMESTAMP() LIMIT 1", [$filename, lang()]);
        }
        $file = ROOT . '/storage/blog/' . $filename;
        if (!$row || !is_file($file)) {
            http_response_code(404);
            return;
        }
        session_write_close();
        header('Content-Type: ' . $row['mime']);
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Cache-Control: private, no-store');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        readfile($file);
    }
}
