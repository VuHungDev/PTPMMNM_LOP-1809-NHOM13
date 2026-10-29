-- backend/database/migrate_v5.sql
-- Bảng heartbeat để API biết worker daemon có đang sống không.
-- Nếu daemon chết (>60s không heartbeat), API tự chấm đồng bộ (fallback)
-- thay vì để bài nộp kẹt QUEUED mãi.

CREATE TABLE IF NOT EXISTS daemon_heartbeat (
    name VARCHAR(50) NOT NULL PRIMARY KEY,
    last_beat TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    pid INT NULL,
    note VARCHAR(255) NULL
) ENGINE=InnoDB;

INSERT INTO daemon_heartbeat (name, pid, note) VALUES ('judge_daemon', NULL, 'Worker chấm bài cách ly')
ON DUPLICATE KEY UPDATE note = VALUES(note);
