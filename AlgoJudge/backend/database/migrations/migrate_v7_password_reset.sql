-- MariaDB 10.4 / XAMPP. Re-running does not reset auth versions.
ALTER TABLE users ADD COLUMN IF NOT EXISTS auth_version INT UNSIGNED NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS password_reset_otps (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used_at DATETIME NULL,
    KEY idx_reset_user_sent (user_id, sent_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_reset_requests (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    email_digest CHAR(64) NOT NULL,
    ip_digest CHAR(64) NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_reset_request_email_time (email_digest, requested_at),
    KEY idx_reset_request_ip_time (ip_digest, requested_at)
) ENGINE=InnoDB;
