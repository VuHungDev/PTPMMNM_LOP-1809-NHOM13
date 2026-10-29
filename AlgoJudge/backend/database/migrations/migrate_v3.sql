-- backend/database/migrate_v3.sql
-- Mở rộng lên 60 bài: mỗi bài gắn 1 ngôn ngữ gợi ý (10 bài / ngôn ngữ × 6 ngôn ngữ).
-- Ngôn ngữ gợi ý chỉ dùng để lọc + chọn sẵn trong IDE, KHÔNG giới hạn ngôn ngữ nộp bài.

ALTER TABLE problems
  ADD COLUMN suggested_lang ENUM('c','cpp','java','python','php','js') NOT NULL DEFAULT 'python';

-- 10 bài gốc (id 1-10) thuộc nhóm Python
UPDATE problems SET suggested_lang = 'python' WHERE id BETWEEN 1 AND 10;
