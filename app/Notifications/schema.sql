CREATE TABLE IF NOT EXISTS {{p}}push_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT UNSIGNED NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    payload TEXT NOT NULL,
    last_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY push_endpoint (endpoint_hash),
    KEY push_session (session_id),
    KEY push_age (updated_at),
    FOREIGN KEY (session_id) REFERENCES {{p}}sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
