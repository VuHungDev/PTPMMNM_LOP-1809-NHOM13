-- backend/database/migrate_v6.sql
-- Sân chơi tự do: lưu vị trí dòng lỗi + thông điệp gọn + gợi ý cho custom_runs.

ALTER TABLE custom_runs
  ADD COLUMN error_line INT NULL COMMENT 'Dòng mã nguồn gây lỗi (nếu xác định được)' AFTER execution_time_ms,
  ADD COLUMN error_message VARCHAR(500) NULL COMMENT 'Thông điệp lỗi gọn (1 dòng)' AFTER error_line,
  ADD COLUMN error_hint VARCHAR(500) NULL COMMENT 'Gợi ý khắc phục tiếng Việt' AFTER error_message;
