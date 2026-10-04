<?php

namespace App\Models;

use App\Blog\Content;

class Blog
{
    private static $ready;

    public static function ready(): bool
    {
        if (self::$ready === null) {
            self::$ready = (int) db()->one('SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?,?)', [config()['db']['prefix'] . 'blog_posts', config()['db']['prefix'] . 'blog_media', config()['db']['prefix'] . 'blog_post_media'])['n'] === 3;
            if (self::$ready) {
                $tableName = config()['db']['prefix'] . 'blog_posts';
                $hasLanguage = (int) db()->one('SELECT COUNT(*) n FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?', [$tableName, 'language'])['n'] > 0;
                if (!$hasLanguage) {
                    try {
                        db()->run('ALTER TABLE ' . db()->table('blog_posts') . " ADD COLUMN language ENUM('ru','ky') NOT NULL DEFAULT 'ru' AFTER title");
                    } catch (\PDOException $exception) {
                        if ((int) ($exception->errorInfo[1] ?? 0) !== 1060) {
                            throw $exception;
                        }
                    }
                }
            }
        }
        return self::$ready;
    }

    public static function migrate(): void
    {
        $prefix = config()['db']['prefix'];
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,20}$/D', $prefix)) {
            throw new \RuntimeException('Invalid prefix');
        }
        $schema = str_replace('{{p}}', $prefix, file_get_contents(ROOT . '/app/Blog/schema.sql'));
        foreach (explode(';', $schema) as $sql) {
            if (trim($sql) !== '') {
                db()->run($sql);
            }
        }
        self::$ready = null;
        if (!self::ready()) {
            throw new \RuntimeException('Blog tables not created');
        }
        if (!is_dir(ROOT . '/storage/blog') && !mkdir(ROOT . '/storage/blog', 0700, true) && !is_dir(ROOT . '/storage/blog')) {
            throw new \RuntimeException('Blog storage not writable');
        }
    }

    public static function latest(int $limit = 3, int $offset = 0, ?string $language = null): array
    {
        if (!self::ready()) {
            return [];
        }
        $language = $language ?? lang();
        if (!in_array($language, ['ru', 'ky'], true)) {
            throw new \InvalidArgumentException('Некорректный язык блога.');
        }
        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);
        return db()->all('SELECT id,title,slug,excerpt,cover,cover_alt,published_at FROM ' . db()->table('blog_posts') . " WHERE language=? AND status='published' AND published_at<=UTC_TIMESTAMP() ORDER BY published_at DESC,id DESC LIMIT " . $limit . ' OFFSET ' . $offset, [$language]);
    }

    public static function slug(string $title): string
    {
        $map = array_combine(
            preg_split('//u', 'абвгдеёжзийклмнопрстуфхцчшщъыьэюяңөү', -1, PREG_SPLIT_NO_EMPTY),
            ['a','b','v','g','d','e','yo','zh','z','i','y','k','l','m','n','o','p','r','s','t','u','f','h','ts','ch','sh','sch','','y','','e','yu','ya','ng','o','u']
        );
        $title = strtr(mb_strtolower($title), $map);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $title), '-');
        return substr($slug === '' ? 'post' : $slug, 0, 150);
    }

    public static function save(array $data, int $adminId): int
    {
        $id = (int) ($data['id'] ?? 0);
        $revision = (int) ($data['revision'] ?? 0);
        $title = trim((string) ($data['title'] ?? ''));
        $language = (string) ($data['language'] ?? 'ru');
        $slug = trim((string) ($data['slug'] ?? ''));
        $slug = $slug === '' ? self::slug($title) : $slug;
        $excerpt = trim((string) ($data['excerpt'] ?? ''));
        $cover = trim((string) ($data['cover'] ?? ''));
        $coverAlt = trim((string) ($data['cover_alt'] ?? ''));
        $status = (string) ($data['status'] ?? 'draft');
        $metaTitle = trim((string) ($data['meta_title'] ?? ''));
        $metaDescription = trim((string) ($data['meta_description'] ?? ''));
        if (!in_array($language, ['ru', 'ky'], true)) {
            throw new \InvalidArgumentException('Выберите язык статьи: русский или кыргызский.');
        }
        if ($title === '' || mb_strlen($title) > 180 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) || strlen($slug) > 160 || mb_strlen($excerpt) > 600 || mb_strlen($coverAlt) > 180 || mb_strlen($metaTitle) > 180 || mb_strlen($metaDescription) > 320 || !in_array($status, ['draft', 'published'], true)) {
            throw new \InvalidArgumentException('Проверьте заголовок, адрес и длину полей записи.');
        }
        if ($cover !== '' && Content::filename($cover) !== $cover) {
            throw new \InvalidArgumentException('Некорректная обложка.');
        }
        $body = Content::normalize((string) ($data['content_json'] ?? ''));
        if ($excerpt === '') {
            $excerpt = mb_substr((string) preg_replace('/\s+/u', ' ', $body['plain']), 0, 240);
        }
        $media = $body['media'];
        if ($cover !== '') {
            $media[] = $cover;
        }
        $media = array_values(array_unique($media));
        $json = json_encode($body['delta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            return db()->transaction(function () use ($id, $revision, $title, $language, $slug, $excerpt, $cover, $coverAlt, $status, $metaTitle, $metaDescription, $media, $json, $adminId) {
                $existing = $id ? db()->one('SELECT * FROM ' . db()->table('blog_posts') . ' WHERE id=? FOR UPDATE', [$id]) : null;
                if ($id && (!$existing || (int) $existing['revision'] !== $revision)) {
                    throw new \InvalidArgumentException('Запись уже изменена в другом окне или удалена. Скопируйте текст и обновите страницу.');
                }
                if (db()->one('SELECT id FROM ' . db()->table('blog_posts') . ' WHERE slug=? AND id<>?', [$slug, $id])) {
                    throw new \InvalidArgumentException('Такой адрес уже занят. Измените URL записи.');
                }
                $mediaIds = [];
                foreach ($media as $filename) {
                    $row = db()->one('SELECT id FROM ' . db()->table('blog_media') . ' WHERE filename=? FOR UPDATE', [$filename]);
                    if (!$row || !is_file(ROOT . '/storage/blog/' . $filename)) {
                        throw new \InvalidArgumentException('Картинка отсутствует. Загрузите её заново.');
                    }
                    $mediaIds[] = (int) $row['id'];
                }
                $published = $existing['published_at'] ?? ($status === 'published' ? gmdate('Y-m-d H:i:s') : null);
                if ($status === 'published' && !$published) {
                    $published = gmdate('Y-m-d H:i:s');
                }
                $values = [$title, $language, $slug, $excerpt, $json, $cover === '' ? null : $cover, $coverAlt, $status, $metaTitle, $metaDescription, $published];
                if ($id) {
                    db()->run('UPDATE ' . db()->table('blog_posts') . ' SET title=?,language=?,slug=?,excerpt=?,content_json=?,cover=?,cover_alt=?,status=?,meta_title=?,meta_description=?,published_at=?,revision=revision+1,updated_at=UTC_TIMESTAMP() WHERE id=?', array_merge($values, [$id]));
                    db()->run('DELETE FROM ' . db()->table('blog_post_media') . ' WHERE post_id=?', [$id]);
                } else {
                    db()->run('INSERT INTO ' . db()->table('blog_posts') . '(title,language,slug,excerpt,content_json,cover,cover_alt,status,meta_title,meta_description,published_at,author_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())', array_merge($values, [$adminId]));
                    $id = db()->id();
                }
                foreach ($mediaIds as $mediaId) {
                    db()->run('INSERT INTO ' . db()->table('blog_post_media') . '(post_id,media_id) VALUES (?,?)', [$id, $mediaId]);
                }
                return $id;
            });
        } catch (\PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new \InvalidArgumentException('Такой адрес уже занят. Измените URL записи.');
            }
            throw $exception;
        }
    }

    public static function date(string $date): string
    {
        return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Bishkek'))->format('d.m.Y');
    }
}
