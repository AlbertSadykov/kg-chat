-- {{p}} заменяется только проверенным префиксом из config.php.
CREATE TABLE IF NOT EXISTS {{p}}blog_posts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    author_id INT UNSIGNED NULL,
    title VARCHAR(180) NOT NULL,
    slug VARCHAR(160) NOT NULL,
    excerpt VARCHAR(600) NOT NULL DEFAULT '',
    content_json MEDIUMTEXT NOT NULL,
    cover VARCHAR(64) NULL,
    cover_alt VARCHAR(180) NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'draft',
    meta_title VARCHAR(180) NOT NULL DEFAULT '',
    meta_description VARCHAR(320) NOT NULL DEFAULT '',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY blog_slug (slug),
    KEY blog_public (status, published_at, id),
    FOREIGN KEY (author_id) REFERENCES {{p}}admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS {{p}}blog_media (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED NULL,
    filename VARCHAR(64) NOT NULL,
    mime VARCHAR(30) NOT NULL,
    size INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY blog_filename (filename),
    KEY blog_media_age (created_at),
    FOREIGN KEY (admin_id) REFERENCES {{p}}admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS {{p}}blog_post_media (
    post_id BIGINT UNSIGNED NOT NULL,
    media_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (post_id, media_id),
    KEY blog_media_post (media_id, post_id),
    FOREIGN KEY (post_id) REFERENCES {{p}}blog_posts(id) ON DELETE CASCADE,
    FOREIGN KEY (media_id) REFERENCES {{p}}blog_media(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
