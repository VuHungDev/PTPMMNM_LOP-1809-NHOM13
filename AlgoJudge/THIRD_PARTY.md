# THIRD_PARTY — Ghi nguồn tái sử dụng

## 1. Mã nhóm tự viết (100% trong repo này, không kể `sandbox/nodejs/node_modules/`)

- `backend/lib/JudgeEngine.php`, `JudgeQueue.php` — điều phối chấm, so sánh output, tổng hợp 6 ngôn ngữ
- `backend/lib/Auth.php`, `backend/config/bootstrap.php`, `backend/config/ai.php` — phân quyền, kết nối CSDL, cấu hình AI
- `backend/lib/AIHelper.php`, `backend/lib/AIAssistant.php` — gọi AI (Gemini), định tuyến ý định, thao tác có xác nhận
- `backend/api/*.php` (12 endpoint: submit, run_custom, save_draft, get_draft, submission_status, session_status, rejudge, retry_run, export_excel, ai_parse_problem, ai_create_problem, ai_chat)
- `backend/worker/judge_daemon.php` — worker chấm cách ly
- `backend/database/schema.sql`, `migrations/migrate_v2..v6.sql`, `seeds/seed_10_problems.php`, `seeds/seed_60_problems.php`
- `backend/tests/run_mandatory_tests.php` — 5 phép thử bắt buộc
- Toàn bộ `frontend/` (giao diện, IDE, console, `partials/ai_widget.php`) và `frontend/assets/css/algojudge.css`, `frontend/assets/js/` (modules, pages, `vendor/alpine.min.js` tự lưu)

## 2. Thư viện & tài nguyên dùng lại (ghi rõ nguồn, giấy phép)

| Tài nguyên | Nguồn | Giấy phép | Dùng ở đâu |
|------------|-------|-----------|------------|
| Monaco Editor 0.45.0 (VS Code Web) | CDN cdnjs (Microsoft) | MIT | `frontend/workspace.php`, `frontend/playground.php` — soạn mã trên trình duyệt |
| Alpine.js (bản tự lưu trong repo) | alpinejs.dev | MIT | `frontend/assets/js/vendor/alpine.min.js` — tương tác form/filter |
| Toolchain chấm bài: Python, GCC/G++ (MSYS2 ucrt64), JDK (`javac`/`java`), Node.js, PHP | python.org, msys2.org, oracle.com/openjdk, nodejs.org, php.net | PSF / GPL+runtime-exception / GPL+Classpath / MIT / PHP License | `backend/lib/JudgeEngine.php` gọi qua CLI cách ly |
| MariaDB/MySQL (XAMPP) | mariadb.org / apachefriends.org | GPL v2 | Lưu trữ toàn bộ dữ liệu |
| Google Gemini API (`gemini-2.0-flash`) | ai.google.dev | Điều khoản Google API (dịch vụ ngoài, key do người dùng tự cấp) | `backend/lib/AIHelper.php` — bóc tách đề, chat trợ lý |
| Thư viện Python có sẵn: numpy, pandas, networkx, nltk, pyjwt, cryptography, Pillow, lxml, beautifulsoup4, Flask, sortedcontainers, requests, scikit-learn, matplotlib, scipy, sympy | PyPI | BSD / MIT / Apache-2.0 (theo từng gói) | Máy chấm — `import` trực tiếp trong code |
| Gói Node sandbox: lodash, mathjs, dayjs, big.js, seedrandom (+ phụ thuộc trong `node_modules/`, không phải mã của nhóm) | npm | MIT | `backend/sandbox/nodejs/` — `require()` trực tiếp trong code JS |
| Extension PHP bật thêm: gd, intl, gmp, sockets, soap, sodium, sqlite3, tidy, xsl | php.net / XAMPP | PHP License | `C:\xampp\php\php.ini` — dùng trong code PHP |
| 60+ đề bài mẫu trong seed | Biên soạn từ các bài kinh điển public-domain (tổng/hiệu, GCD, số nguyên tố, Fibonacci, mảng, chuỗi, tìm kiếm...) | Public domain / tự biên soạn | `backend/database/seeds/` |

Không nhúng mã nguồn thư viện vào repo (trừ `alpine.min.js` và `sandbox/nodejs/node_modules/` phục vụ máy chấm);
chỉ gọi qua CDN, API ngoài hoặc tiến trình CLI có sẵn trên máy chấm.
