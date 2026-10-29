CREATE DATABASE IF NOT EXISTS algojudge CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE algojudge;

-- 1. Bảng người dùng (Phân quyền: student, instructor, admin + phân tách theo class_code)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('student', 'instructor', 'admin') NOT NULL DEFAULT 'student',
    class_code VARCHAR(30) NOT NULL DEFAULT 'CNTT-18-02',
    xp_points INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Bảng bài tập (problems)
CREATE TABLE problems (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    title VARCHAR(200) NOT NULL,
    topic VARCHAR(80) NOT NULL,
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
    language ENUM('python', 'cpp') NOT NULL DEFAULT 'python',
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
    language ENUM('python', 'cpp') NOT NULL,
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
    language ENUM('python', 'cpp') NOT NULL,
    source_code MEDIUMTEXT NOT NULL,
    custom_stdin TEXT NOT NULL,
    status ENUM('QUEUED', 'RUNNING', 'DONE') NOT NULL DEFAULT 'QUEUED',
    verdict VARCHAR(20) DEFAULT 'PENDING',
    stdout_output TEXT NULL,
    stderr_output TEXT NULL,
    execution_time_ms INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
