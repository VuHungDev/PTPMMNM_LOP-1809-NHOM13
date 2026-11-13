-- Additive only. No existing application rows are changed.
-- Chat state is private to a user + login scope; old scopes expire after 7 days.
CREATE TABLE IF NOT EXISTS chatbot_sessions (
    id CHAR(64) NOT NULL PRIMARY KEY,
    user_id INT NOT NULL,
    auth_version INT UNSIGNED NOT NULL,
    role VARCHAR(16) NOT NULL,
    messages MEDIUMTEXT NOT NULL,
    draft MEDIUMTEXT NULL,
    pending_source MEDIUMTEXT NULL,
    lease_token CHAR(32) NULL,
    lease_heartbeat BIGINT UNSIGNED NULL,
    rate_started BIGINT UNSIGNED NOT NULL DEFAULT 0,
    rate_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_chatbot_expiry (updated_at),
    KEY idx_chatbot_owner (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Keep confirmation receipts if the current draft is replaced. Removed with expired session.
CREATE TABLE IF NOT EXISTS chatbot_creations (
    session_id CHAR(64) NOT NULL,
    user_id INT NOT NULL,
    draft_id CHAR(32) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    save_key CHAR(48) NOT NULL,
    envelope MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (session_id, draft_id, revision),
    UNIQUE KEY uq_chatbot_save_key (save_key),
    FOREIGN KEY (session_id) REFERENCES chatbot_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
