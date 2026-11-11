-- Additive upgrade for an existing v8 database. Back up before applying.
-- MariaDB 10.4: safe to rerun; no user credentials or existing rows are changed.
ALTER TABLE password_reset_otps
    ADD COLUMN IF NOT EXISTS purpose VARCHAR(32) NOT NULL DEFAULT 'password_reset',
    ADD COLUMN IF NOT EXISTS context_hash CHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS auth_version INT UNSIGNED NULL;

-- Snapshot IDs intentionally have no cascading foreign keys: deleting an account
-- or target must not erase a completed administrative action. No retroactive backfill.
CREATE TABLE IF NOT EXISTS admin_activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id INT NOT NULL,
    action VARCHAR(64) NOT NULL,
    target_type VARCHAR(24) NOT NULL,
    target_id INT NOT NULL,
    metadata TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_admin_activity_actor_id (actor_id, id)
) ENGINE=InnoDB;
