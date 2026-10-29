-- backend/database/migrate_v2.sql
-- Nâng cấp từ bản demo cũ (python/cpp, instructor) lên 6 ngôn ngữ + 3 vai trò.
-- Chạy sau schema.sql / trên CSDL đã có dữ liệu.

ALTER TABLE drafts
  MODIFY COLUMN language ENUM('c','cpp','java','python','php','js') NOT NULL DEFAULT 'python';

ALTER TABLE submissions
  MODIFY COLUMN language ENUM('c','cpp','java','python','php','js') NOT NULL;

ALTER TABLE custom_runs
  MODIFY COLUMN language ENUM('c','cpp','java','python','php','js') NOT NULL;

-- Đổi tên vai trò giảng viên: instructor -> teacher (giữ nguyên admin/student)
ALTER TABLE users
  MODIFY COLUMN role ENUM('student','instructor','teacher','admin') NOT NULL DEFAULT 'student';

UPDATE users SET role = 'teacher' WHERE role = 'instructor';

ALTER TABLE users
  MODIFY COLUMN role ENUM('student','teacher','admin') NOT NULL DEFAULT 'student';
