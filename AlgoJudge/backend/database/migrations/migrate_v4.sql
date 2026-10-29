-- backend/database/migrate_v4.sql
-- Bổ sung khóa/mở tài khoản cho trang quản trị admin.

ALTER TABLE users
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1: hoạt động, 0: bị khóa';
