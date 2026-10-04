-- {{p}} заменяется установщиком на проверенный префикс. InnoDB обязателен.
CREATE TABLE {{p}}cities (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name_ru VARCHAR(80) NOT NULL, name_ky VARCHAR(80) NOT NULL,
 enabled TINYINT NOT NULL DEFAULT 1, INDEX(enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 nickname VARCHAR(40) NOT NULL, gender CHAR(1) NOT NULL, age TINYINT UNSIGNED NOT NULL,
 city_id INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL,
 FOREIGN KEY(city_id) REFERENCES {{p}}cities(id), INDEX(created_at), INDEX(city_id,gender,age)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}sessions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL UNIQUE,
 ip_hash CHAR(64) NULL, fingerprint CHAR(64) NULL,
 last_seen DATETIME NOT NULL, identity_at DATETIME NOT NULL,
 typing_until DATETIME NULL, delivered_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 read_id BIGINT UNSIGNED NOT NULL DEFAULT 0, warning VARCHAR(500) NULL,
 FOREIGN KEY(user_id) REFERENCES {{p}}users(id) ON DELETE CASCADE,
 INDEX(last_seen), INDEX(ip_hash), INDEX(fingerprint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}chats (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 a BIGINT UNSIGNED NOT NULL, b BIGINT UNSIGNED NOT NULL,
 started_at DATETIME NOT NULL, ended_at DATETIME NULL, end_reason VARCHAR(40) NULL,
 last_activity DATETIME NOT NULL,
 FOREIGN KEY(a) REFERENCES {{p}}sessions(id) ON DELETE CASCADE,
 FOREIGN KEY(b) REFERENCES {{p}}sessions(id) ON DELETE CASCADE,
 INDEX(a,ended_at), INDEX(b,ended_at), INDEX(ended_at,started_at), INDEX(last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}queue (
 session_id BIGINT UNSIGNED PRIMARY KEY, city_id INT UNSIGNED NULL,
 gender CHAR(1) NULL, min_age TINYINT UNSIGNED NOT NULL, max_age TINYINT UNSIGNED NOT NULL,
 joined_at DATETIME NOT NULL, expires_at DATETIME NOT NULL,
 FOREIGN KEY(session_id) REFERENCES {{p}}sessions(id) ON DELETE CASCADE,
 FOREIGN KEY(city_id) REFERENCES {{p}}cities(id), INDEX(joined_at), INDEX(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, chat_id BIGINT UNSIGNED NOT NULL,
 sender_id BIGINT UNSIGNED NOT NULL, body TEXT NOT NULL, nonce VARCHAR(64) NOT NULL,
 created_at DATETIME NOT NULL,
 FOREIGN KEY(chat_id) REFERENCES {{p}}chats(id) ON DELETE CASCADE,
 FOREIGN KEY(sender_id) REFERENCES {{p}}sessions(id) ON DELETE CASCADE,
 UNIQUE(chat_id,sender_id,nonce), INDEX(chat_id,id), INDEX(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}message_likes (
 message_id BIGINT UNSIGNED NOT NULL, session_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,
 PRIMARY KEY(message_id,session_id), INDEX(session_id),
 FOREIGN KEY(message_id) REFERENCES {{p}}messages(id) ON DELETE CASCADE,
 FOREIGN KEY(session_id) REFERENCES {{p}}sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}message_replies (
 message_id BIGINT UNSIGNED PRIMARY KEY, reply_to_message_id BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(message_id) REFERENCES {{p}}messages(id) ON DELETE CASCADE,
 FOREIGN KEY(reply_to_message_id) REFERENCES {{p}}messages(id) ON DELETE CASCADE,
 INDEX(reply_to_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}admins (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, login VARCHAR(64) NOT NULL UNIQUE,
 password VARCHAR(255) NOT NULL, email VARCHAR(190) NOT NULL, role VARCHAR(16) NOT NULL,
 totp_secret VARCHAR(64) NULL, totp_last BIGINT NOT NULL DEFAULT -1,
 enabled TINYINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}reports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, chat_id BIGINT UNSIGNED NULL,
 reporter_id BIGINT UNSIGNED NULL, target_id BIGINT UNSIGNED NULL,
 reason VARCHAR(40) NOT NULL, detail VARCHAR(500) NOT NULL DEFAULT '',
 priority TINYINT NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'pending',
 evidence MEDIUMTEXT NOT NULL, created_at DATETIME NOT NULL,
 resolved_at DATETIME NULL, admin_id INT UNSIGNED NULL,
 FOREIGN KEY(chat_id) REFERENCES {{p}}chats(id) ON DELETE SET NULL,
 FOREIGN KEY(reporter_id) REFERENCES {{p}}sessions(id) ON DELETE SET NULL,
 FOREIGN KEY(target_id) REFERENCES {{p}}sessions(id) ON DELETE SET NULL,
 FOREIGN KEY(admin_id) REFERENCES {{p}}admins(id) ON DELETE SET NULL,
 INDEX(status,priority,created_at), INDEX(created_at), INDEX(chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}bans (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, scope VARCHAR(16) NOT NULL,
 value VARCHAR(64) NOT NULL, reason VARCHAR(500) NOT NULL,
 expires_at DATETIME NULL, created_at DATETIME NOT NULL,
 admin_id INT UNSIGNED NULL, revoked_at DATETIME NULL,
 FOREIGN KEY(admin_id) REFERENCES {{p}}admins(id) ON DELETE SET NULL,
 INDEX(scope,value,revoked_at,expires_at), INDEX(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}admin_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, admin_id INT UNSIGNED NULL,
 action VARCHAR(60) NOT NULL, detail VARCHAR(1000) NOT NULL, created_at DATETIME NOT NULL,
 FOREIGN KEY(admin_id) REFERENCES {{p}}admins(id) ON DELETE SET NULL, INDEX(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}settings (
 name VARCHAR(64) PRIMARY KEY, value MEDIUMTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}stopwords (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(16) NOT NULL,
 pattern VARCHAR(190) NOT NULL, action VARCHAR(16) NOT NULL,
 replacement VARCHAR(190) NOT NULL DEFAULT '***', enabled TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}rate_limits (
 bucket CHAR(64) PRIMARY KEY, hits INT UNSIGNED NOT NULL, expires_at DATETIME NOT NULL,
 INDEX(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO {{p}}settings(name,value) VALUES ('_match_lock','1'),('_cleanup_at','0');
