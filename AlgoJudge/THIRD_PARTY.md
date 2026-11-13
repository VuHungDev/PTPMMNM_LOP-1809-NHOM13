# THIRD_PARTY — Ghi nguồn tái sử dụng

## 1. Mã nhóm viết

- `backend/lib/JudgeEngine.php`, `JudgeQueue.php` — điều phối chấm, so sánh output, tổng hợp 6 ngôn ngữ
- `backend/lib/Auth.php`, `backend/config/bootstrap.php`, `backend/config/ai.php` — phân quyền, kết nối CSDL, cấu hình AI
- `backend/lib/AIHelper.php`, `backend/lib/AIAssistant.php` — gọi AI (Gemini), định tuyến ý định, thao tác có xác nhận
- `backend/api/*.php` (12 endpoint: submit, run_custom, save_draft, get_draft, submission_status, session_status, rejudge, retry_run, export_excel, ai_parse_problem, ai_create_problem, ai_chat)
- `backend/worker/judge_daemon.php` — worker chấm cách ly
- `backend/database/schema.sql`, `migrations/migrate_v2..v6.sql`, `migrations/migrate_v7_password_reset.sql`, `migrations/migrate_v8_classes.sql`, `migrations/migrate_v9_profile_audit.sql`, các seed bài tập
- `backend/lib/PasswordReset.php`, `Mailer.php`, `ClassManager.php`, `AuditLog.php` — OTP khôi phục/đổi mật khẩu/đổi email, tích hợp SMTP, quản lý lớp và nhật ký quản trị theo giao dịch
- `backend/tests/run_mandatory_tests.php` — 5 phép thử bắt buộc; các bộ `run_upgrade_tests.php`, `run_migration_tests.php`, `http_upgrade_tests.mjs`, `browser_upgrade_smoke.mjs`, `profile_security_tests.php`, `student_class_tests.php`, `audit_log_tests.php`, `http_audit_tests.mjs` — kiểm thử nâng cấp trên dữ liệu giả lập tách biệt
- Toàn bộ `frontend/` (giao diện, IDE, console, `partials/ai_widget.php`) và `frontend/assets/css/algojudge.css`, `frontend/assets/js/` (modules, pages, `vendor/alpine.min.js` tự lưu)

## 2. Thư viện & tài nguyên dùng lại (ghi rõ nguồn, giấy phép)

| Tài nguyên | Nguồn | Giấy phép | Dùng ở đâu |
|------------|-------|-----------|------------|
| Monaco Editor 0.45.0 (VS Code Web) | CDN cdnjs (Microsoft) | MIT | `frontend/workspace.php`, `frontend/playground.php` — soạn mã trên trình duyệt |
| Alpine.js (bản tự lưu trong repo) | alpinejs.dev | MIT | `frontend/assets/js/vendor/alpine.min.js` — tương tác form/filter |
| PHPMailer (`phpmailer/phpmailer`, yêu cầu `^6.9`) | https://github.com/PHPMailer/PHPMailer / Packagist | LGPL-2.1 | `backend/lib/Mailer.php` — gửi OTP qua SMTP; khai báo trong `composer.json`, cần cài dependency trước khi dùng |
| Toolchain chấm bài: Python, GCC/G++ (MSYS2 ucrt64), JDK (`javac`/`java`), Node.js, PHP | python.org, msys2.org, oracle.com/openjdk, nodejs.org, php.net | PSF / GPL+runtime-exception / GPL+Classpath / MIT / PHP License | `backend/lib/JudgeEngine.php` gọi qua CLI cách ly |
| MariaDB/MySQL (XAMPP) | mariadb.org / apachefriends.org | GPL v2 | Lưu trữ toàn bộ dữ liệu |
| Google Gemini API (`gemini-3.5-flash`, có thể đổi bằng `AI_MODEL`) | ai.google.dev | Điều khoản Google API (dịch vụ ngoài, key do người dùng tự cấp) | `backend/lib/AIStream.php`, `backend/lib/AIHelper.php` — stream bản nháp và bóc tách đề |
| Thư viện Python có sẵn: numpy, pandas, networkx, nltk, pyjwt, cryptography, Pillow, lxml, beautifulsoup4, Flask, sortedcontainers, requests, scikit-learn, matplotlib, scipy, sympy | PyPI | BSD / MIT / Apache-2.0 (theo từng gói) | Máy chấm — `import` trực tiếp trong code |
| Gói Node sandbox: lodash, mathjs, dayjs, big.js, seedrandom (+ phụ thuộc trong `node_modules/`, không phải mã của nhóm) | npm | MIT | `backend/sandbox/nodejs/` — `require()` trực tiếp trong code JS |
| Extension PHP bật thêm: gd, intl, gmp, sockets, soap, sodium, sqlite3, tidy, xsl | php.net / XAMPP | PHP License | `C:\xampp\php\php.ini` — dùng trong code PHP |
| 60+ đề bài mẫu trong seed | Biên soạn từ các bài kinh điển public-domain (tổng/hiệu, GCD, số nguyên tố, Fibonacci, mảng, chuỗi, tìm kiếm...) | Public domain / tự biên soạn | `backend/database/seeds/` |

Không nhúng mã nguồn thư viện vào repo (trừ `alpine.min.js` và `sandbox/nodejs/node_modules/` phục vụ máy chấm);
chỉ gọi qua CDN, API ngoài hoặc tiến trình CLI có sẵn trên máy chấm.
