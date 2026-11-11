CREATE DATABASE IF NOT EXISTS algojudge CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE algojudge;

-- Cài mới: schema này đã bao gồm v2-v9; không cần chạy lại các migration cũ.
-- 1. Phân quyền qua role + classes/class_members; class_code chỉ dành cho import cũ.
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('student', 'teacher', 'admin') NOT NULL DEFAULT 'student',
    class_code VARCHAR(30) NOT NULL DEFAULT '',
    xp_points INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    auth_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE classes (
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

CREATE TABLE class_members (
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

CREATE TABLE password_reset_otps (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    purpose VARCHAR(32) NOT NULL DEFAULT 'password_reset',
    context_hash CHAR(64) NULL,
    auth_version INT UNSIGNED NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used_at DATETIME NULL,
    KEY idx_reset_user_sent (user_id, sent_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE password_reset_requests (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    email_digest CHAR(64) NOT NULL,
    ip_digest CHAR(64) NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_reset_request_email_time (email_digest, requested_at),
    KEY idx_reset_request_ip_time (ip_digest, requested_at)
) ENGINE=InnoDB;

-- Administrative history starts when logging is deployed; IDs survive target deletion.
CREATE TABLE admin_activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id INT NOT NULL,
    action VARCHAR(64) NOT NULL,
    target_type VARCHAR(24) NOT NULL,
    target_id INT NOT NULL,
    metadata TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_admin_activity_actor_id (actor_id, id)
) ENGINE=InnoDB;

-- 2. Bảng bài tập (problems)
CREATE TABLE problems (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    title VARCHAR(200) NOT NULL,
    topic VARCHAR(80) NOT NULL,
    suggested_lang ENUM('c','cpp','java','python','php','js') NOT NULL DEFAULT 'python',
    difficulty ENUM('Easy', 'Medium', 'Hard') NOT NULL DEFAULT 'Easy',
    current_version_id INT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- 3. Bảng phiên bản đề và bộ dữ liệu kiểm tra (problem_versions)
CREATE TABLE problem_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    problem_id INT NOT NULL,
    version_number INT NOT NULL,
    description TEXT NOT NULL,
    input_desc TEXT NOT NULL,
    output_desc TEXT NOT NULL,
    sample_input TEXT NOT NULL,
    sample_output TEXT NOT NULL,
    time_limit_ms INT NOT NULL DEFAULT 1000,
    memory_limit_kb INT NOT NULL DEFAULT 65536,
    changelog VARCHAR(255) DEFAULT 'Phiên bản khởi tạo',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_prob_ver (problem_id, version_number),
    FOREIGN KEY (problem_id) REFERENCES problems(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- 4. Bảng ca kiểm thử (test_cases)
CREATE TABLE test_cases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    problem_version_id INT NOT NULL,
    case_order INT NOT NULL,
    stdin_data MEDIUMTEXT NOT NULL,
    expected_output MEDIUMTEXT NOT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1: Ví dụ công khai, 0: Test chấm kín',
    is_boundary TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1: Dữ liệu biên',
    points INT NOT NULL DEFAULT 20,
    FOREIGN KEY (problem_version_id) REFERENCES problem_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. Bảng lưu bản nháp trên trình duyệt (drafts)
CREATE TABLE drafts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    problem_id INT NOT NULL,
    language ENUM('c','cpp','java','python','php','js') NOT NULL DEFAULT 'python',
    source_code MEDIUMTEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_prob_lang (user_id, problem_id, language),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (problem_id) REFERENCES problems(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 6. Bảng bài nộp (submissions) - Có idempotency_key chống nộp trùng & latest_run_id chống ghi đè kết quả muộn
CREATE TABLE submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    idempotency_key VARCHAR(64) NOT NULL UNIQUE COMMENT 'Khóa chống gửi trùng yêu cầu nộp',
    user_id INT NOT NULL,
    problem_id INT NOT NULL,
    problem_version_id INT NOT NULL COMMENT 'Phiên bản đề lúc nộp',
    language ENUM('c','cpp','java','python','php','js') NOT NULL,
    source_code MEDIUMTEXT NOT NULL,
    latest_run_id INT NULL COMMENT 'Chỉ cập nhật nếu run_id mới >= latest_run_id hiện tại',
    final_verdict ENUM('QUEUED', 'RUNNING', 'AC', 'WA', 'TLE', 'RE', 'CE', 'SYS_ERR') NOT NULL DEFAULT 'QUEUED',
    final_score INT NOT NULL DEFAULT 0,
    pass_count INT NOT NULL DEFAULT 0,
    total_cases INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (problem_id) REFERENCES problems(id),
    FOREIGN KEY (problem_version_id) REFERENCES problem_versions(id)
) ENGINE=InnoDB;

-- 7. Bảng các lần thực thi chấm bài (judging_runs) - Lưu lịch sử mọi lần chấm & chấm lại
CREATE TABLE judging_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    problem_version_id INT NOT NULL COMMENT 'Phiên bản bộ test dùng trong lần chấm này',
    run_number INT NOT NULL DEFAULT 1,
    trigger_type ENUM('INITIAL', 'REJUDGE', 'SYS_RETRY') NOT NULL DEFAULT 'INITIAL',
    triggered_by INT NOT NULL,
    status ENUM('QUEUED', 'RUNNING', 'COMPLETED', 'SYS_ERROR') NOT NULL DEFAULT 'QUEUED',
    verdict ENUM('PENDING', 'AC', 'WA', 'TLE', 'RE', 'CE', 'SYS_ERR') NOT NULL DEFAULT 'PENDING',
    score INT NOT NULL DEFAULT 0,
    pass_count INT NOT NULL DEFAULT 0,
    total_cases INT NOT NULL DEFAULT 0,
    max_time_ms INT NOT NULL DEFAULT 0,
    compiler_output TEXT NULL,
    system_error_detail TEXT NULL COMMENT 'Chỉ lưu nội bộ cho Admin, không trả ra trình duyệt',
    started_at TIMESTAMP NULL,
    finished_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
    FOREIGN KEY (problem_version_id) REFERENCES problem_versions(id),
    FOREIGN KEY (triggered_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- 8. Bảng kết quả từng test case của một lần chấm (case_results)
CREATE TABLE case_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    judging_run_id INT NOT NULL,
    test_case_id INT NOT NULL,
    verdict ENUM('AC', 'WA', 'TLE', 'RE', 'SYS_ERR') NOT NULL,
    execution_time_ms INT NOT NULL DEFAULT 0,
    exit_code INT NOT NULL DEFAULT 0,
    actual_output MEDIUMTEXT NULL COMMENT 'Chỉ hiển thị cho người học nếu test_case.is_public = 1',
    runtime_error TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (judging_run_id) REFERENCES judging_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (test_case_id) REFERENCES test_cases(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 9. Bảng hàng đợi chạy thử dữ liệu tự nhập (custom_runs) - Đảm bảo không chạy code trực tiếp trong Web process
CREATE TABLE custom_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    language ENUM('c','cpp','java','python','php','js') NOT NULL,
    source_code MEDIUMTEXT NOT NULL,
    custom_stdin TEXT NOT NULL,
    status ENUM('QUEUED', 'RUNNING', 'DONE') NOT NULL DEFAULT 'QUEUED',
    verdict VARCHAR(20) DEFAULT 'PENDING',
    stdout_output TEXT NULL,
    stderr_output TEXT NULL,
    execution_time_ms INT DEFAULT 0,
    error_line INT NULL,
    error_message VARCHAR(500) NULL,
    error_hint VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE daemon_heartbeat (
    name VARCHAR(50) NOT NULL PRIMARY KEY,
    last_beat TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    pid INT NULL,
    note VARCHAR(255) NULL
) ENGINE=InnoDB;

