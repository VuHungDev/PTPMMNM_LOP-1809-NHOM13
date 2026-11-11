-- Keep legacy values only for import; never give new accounts an implicit class.
ALTER TABLE users ALTER COLUMN class_code SET DEFAULT '';

CREATE TABLE IF NOT EXISTS classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    legacy_class_code VARCHAR(30) NULL UNIQUE,
    join_code CHAR(16) NULL UNIQUE,
    owner_teacher_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_teacher_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS class_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    user_id INT NOT NULL,
    status ENUM('pending', 'active', 'rejected', 'removed') NOT NULL DEFAULT 'pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_class_member (class_id, user_id),
    KEY idx_member_user_status (user_id, status),
    KEY idx_member_class_status (class_id, status),
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO classes (name, legacy_class_code, join_code, owner_teacher_id, created_by)
SELECT CONCAT('Lớp ', u.class_code), u.class_code, NULL,
       CASE WHEN COUNT(DISTINCT CASE WHEN t.role = 'teacher' THEN t.id END) = 1
            THEN MAX(CASE WHEN t.role = 'teacher' THEN t.id END) ELSE NULL END,
       CASE WHEN COUNT(DISTINCT CASE WHEN t.role = 'teacher' THEN t.id END) = 1
            THEN MAX(CASE WHEN t.role = 'teacher' THEN t.id END) ELSE NULL END
FROM users u
LEFT JOIN users t ON t.role = 'teacher' AND t.class_code = u.class_code
WHERE u.role IN ('student', 'teacher') AND u.class_code <> '' AND u.class_code <> 'ALL'
GROUP BY u.class_code;

INSERT IGNORE INTO class_members (class_id, user_id, status)
SELECT c.id, u.id, 'active'
FROM users u
JOIN classes c ON c.legacy_class_code = u.class_code
WHERE u.role = 'student' AND u.class_code <> '';
