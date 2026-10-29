# THIRD_PARTY — Ghi nguồn tái sử dụng

## 1. Mã nhóm tự viết (100% trong repo này)

- `backend/lib/JudgeEngine.php` — điều phối chấm, so sánh output, tổng hợp 6 ngôn ngữ
- `backend/lib/Auth.php`, `backend/config/bootstrap.php` — phân quyền, kết nối CSDL
- `backend/api/*.php` (6 endpoint), `backend/worker/judge_daemon.php`
- `backend/database/schema.sql`, `seed_10_problems.php`, `migrate_v2.sql`
- `backend/tests/run_mandatory_tests.php`
- Toàn bộ `frontend/` (giao diện, IDE, console) và `frontend/assets/css/algojudge.css`

## 2. Thư viện & tài nguyên dùng lại (ghi rõ nguồn, giấy phép)

| Tài nguyên | Nguồn | Giấy phép | Dùng ở đâu |
|------------|-------|-----------|------------|
| Monaco Editor 0.45.0 (VS Code Web) | CDN cdnjs (Microsoft) | MIT | `frontend/workspace.php`, `frontend/playground.php` — bộ soạn mã trên trình duyệt |
| Toolchain chấm bài: Python, GCC/G++ (MSYS2 ucrt64), JDK (`javac`/`java`), Node.js, PHP | python.org, msys2.org, oracle.com/openjdk, nodejs.org, php.net | PSF / GPL+runtime-exception / GPL+Classpath / MIT / PHP License | `backend/lib/JudgeEngine.php` gọi qua CLI cách ly |
| MariaDB/MySQL (XAMPP) | mariadb.org / apachefriends.org | GPL v2 | Lưu trữ toàn bộ dữ liệu |
| Thư viện Python có sẵn: numpy, pandas, networkx, nltk, pyjwt, cryptography, Pillow, lxml, beautifulsoup4, Flask, sortedcontainers, requests, scikit-learn, matplotlib, scipy, sympy | PyPI | BSD / MIT / Apache-2.0 (theo từng gói) | Máy chấm — `import` trực tiếp trong code |
| Gói Node sandbox: lodash, mathjs, dayjs, big.js, seedrandom | npm | MIT | `backend/sandbox/nodejs/` — `require()` trực tiếp trong code JS |
| Extension PHP bật thêm: gd, intl, gmp, sockets, soap, sodium, sqlite3, tidy, xsl | php.net / XAMPP | PHP License | `C:\xampp\php\php.ini` — dùng trong code PHP |
| 60 đề bài mẫu trong seed | Biên soạn từ các bài kinh điển public-domain (mỗi ngôn ngữ 10 bài: tổng/hiệu, GCD, số nguyên tố, Fibonacci, mảng, chuỗi, tìm kiếm...) | Public domain / tự biên soạn | `backend/database/seed_10_problems.php`, `seed_60_problems.php` |

Không nhúng mã nguồn của thư viện vào repo; chỉ gọi qua CDN hoặc tiến trình CLI có sẵn trên máy chấm.
