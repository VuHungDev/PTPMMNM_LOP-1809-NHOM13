# AlgoJudge — Hệ thống Luyện tập & Chấm bài Lập trình Trực tuyến

Hệ thống luyện tập và chấm bài tự động với các bài tập( 6 ngôn ngữ C / C++ / Java / Python / PHP / JS),
chạy trên **XAMPP + MySQL**, dùng được cả **Windows & Linux**, với
bộ soạn thảo **Monaco Editor **.

## 1. Kiến trúc:

```text
AlgoJudge/
├── index.php              → redirect về frontend/
├── backend/               → PHẦN XỬ LÝ + LƯU TRỮ (nhóm tự viết)
│   ├── config/bootstrap.php   → kết nối MySQL (thử 3307 rồi 3306), hằng số, helper
│   ├── lib/Auth.php           → đăng nhập, phân quyền 3 vai trò, kiểm tra xem mã
│   ├── lib/JudgeEngine.php    → điều phối chấm, so sánh output, tổng hợp (6 ngôn ngữ)
│   ├── lib/JudgeQueue.php     → điều phối hàng đợi + heartbeat + fallback khi daemon chết
│   ├── api/                   → submit, run_custom, save_draft, get_draft,
│   │                            submission_status, rejudge, export_excel (JSON/xls)
│   ├── worker/judge_daemon.php→ CLI worker cách ly (không chạy trong Web)
│   ├── database/              → schema.sql, seed_10_problems.php (10 bài gốc),
│   │                            seed_60_problems.php (50 bài mới), migrate_v2/v3/v4.sql
│   ├── reference_solutions/   → mã đúng/sai đối chiếu 10 bài gốc
│   └── tests/                 → run_mandatory_tests.php (5 phép thử bắt buộc)
└── frontend/              → PHẦN GIAO DIỆN (nhóm tự viết giao diện, dùng lại thư viện)
    ├── index.php (lọc chủ đề/độ khó/**ngôn ngữ** + phân trang) / workspace.php (IDE tự chọn ngôn ngữ gợi ý) / submissions.php (lọc + phân trang + xuất Excel)
    ├── leaderboard.php (BXH XP) / profile.php (hồ sơ cá nhân)
    ├── auth/ / instructor/ (problems CRUD, dataset_versions, queue, progress) / admin/ / partials/
    └── assets/css + assets/js
```

## 2. Tài khoản demo (mật khẩu chung `123456`)

| Username   | Vai trò   | Lớp        |
|------------|-----------|------------|
| `sv_son`   | student   | CNTT-18-02 |
| `sv_phuong`| student   | CNTT-18-02 |
| `sv_khaclop`| student  | CNTT-18-01 |
| `gv_cntt`  | teacher   | CNTT-18-02 |
| `admin`    | admin     | ALL        |

Đăng ký mới chỉ tạo được tài khoản **student**; admin cấp tài khoản mọi vai trò trong trang Quản trị User
(tạo user chọn vai trò student/teacher/admin, đổi vai trò/lớp, reset mật khẩu, **khóa/mở tài khoản**,
xóa tài khoản chưa có dữ liệu — tài khoản còn bài nộp/bài tập chỉ được khóa để giữ lịch sử).

## 3. Cài đặt & chạy trên XAMPP (Windows)

**Bước 0 — Khởi động MySQL của XAMPP** (XAMPP Control Panel → MySQL → Start).
Project thử cổng `3307` trước rồi `3306`, user `root`, mật khẩu rỗng.

**Bước 1 — Khởi tạo CSDL & dữ liệu mẫu (60 bài, 300 test cases):**

```bash
C:\xampp\mysql\bin\mysql.exe -u root < backend\database\schema.sql
C:\xampp\mysql\bin\mysql.exe -u root algojudge < backend\database\migrate_v2.sql
C:\xampp\mysql\bin\mysql.exe -u root algojudge < backend\database\migrate_v3.sql
C:\xampp\mysql\bin\mysql.exe -u root algojudge < backend\database\migrate_v4.sql
C:\xampp\php\php.exe backend\database\seed_10_problems.php
C:\xampp\php\php.exe backend\database\seed_60_problems.php
```

Mỗi bài gắn 1 **ngôn ngữ gợi ý** (`suggested_lang`, 10 bài × 6 ngôn ngữ) để lọc ở trang Luyện tập;
IDE tự chọn sẵn ngôn ngữ đó nhưng học viên vẫn được nộp mọi ngôn ngữ.

(Nếu đã có CSDL từ bản demo cũ: chạy `migrate_v2.sql` → `migrate_v4.sql` rồi seed lại.)

**Bước 2 — Mở ứng dụng:** Apache (XAMPP) → Start, rồi vào
`http://localhost/AlgoJudge/` (tự chuyển về `frontend/`).

**Bước 2b — Chạy Judge Daemon thường trực (khuyên dùng):** mở file `start-judge-daemon.bat`
(chuột phải → Run), giữ cửa sổ mở trong suốt buổi chấm. Khi daemon sống, nút Chạy thử /
Nộp bài phản hồi tức thì. Nếu quên mở, hệ thống tự chấm fallback trong request
(vẫn chạy trong tiến trình OS con riêng, không eval trong Web) nên không bao giờ kẹt QUEUED.

**Bước 3 — Cài toolchain chấm bài** (cần cho các ngôn ngữ muốn chấm):
`python`, `g++`/`gcc`, `javac`/`java`, `node`. PHP dùng chính PHP của XAMPP.
Nếu thiếu toolchain của ngôn ngữ nào (VD máy chưa cài `g++`), bài nộp ngôn ngữ đó sẽ nhận
`CE` kèm thông báo thiếu trình biên dịch — không ảnh hưởng các ngôn ngữ khác.

**Bước 4 — Chạy kiểm thử 5 phép thử bắt buộc:**

```bash
C:\xampp\php\php.exe backend\tests\run_mandatory_tests.php
```

## 4. Hai luồng demo

**Tình huống 1 (học viên):** Đăng nhập `sv_son/123456` → Chọn bài → viết mã (chọn 1 trong 6 ngôn ngữ)
→ Chạy thử (Custom Input) → Nộp bài → theo dõi chấm → xem kết quả & mã đã nộp.

**Tình huống 2 (giảng viên):** Đăng nhập `gv_cntt/123456` → Quản lý Bài tập (tạo/sửa/ẩn hiện)
→ Bộ Test & Chấm lại (tạo phiên bản mới → chấm lại → đối chiếu 2 lần chấm, lịch sử giữ nguyên)
→ Tiến độ HV (theo dõi, xuất Excel).

## 5. Danh sách verdict & quy tắc đã công bố

`AC` (đúng hết) · `WA` (sai đáp án, kể cả sai biên) · `CE` (lỗi biên dịch/cú pháp) ·
`RE` (lỗi khi chạy, exit code ≠ 0) · `TLE` (vượt giới hạn thời gian / lặp vô hạn) ·
`SYS_ERR` (bộ thực thi bị ngắt — không chấm người học sai, chờ thử lại).

- Gửi lại cùng yêu cầu nộp (cùng `idempotency_key`) → trả về đúng mã bài nộp cũ, không tạo bản trùng.
- Một yêu cầu nộp mới có chủ ý (khóa khác) → tạo bài nộp mới.
- Kết quả của lần chấm cũ về sau không ghi đè lần chấm mới (`latest_run_id`).
- Người A không đọc được mã riêng của B; API/giao diện không lộ test kín, đáp án hay thông tin kết nối bộ thực thi.

## 6. Tính năng mở rộng

- **Phân trang** mọi danh sách dài: bài tập (8/trang), bài nộp (15/trang + lọc theo bài/kết quả/lớp/username), user (10/trang + lọc vai trò/từ khóa).
- **Xuất Excel (.xls)** mở trực tiếp bằng Excel: lịch sử bài nộp (theo đúng bộ lọc hiện tại) và báo cáo tiến độ học viên (số bài AC, tỉ lệ %, lần nộp cuối).
- **Quản lý bài tập (CRUD)** cho giảng viên: tạo bài mới (slug, mô tả, ví dụ, bộ test nhiều ca), sửa (tự tạo phiên bản mới giữ lịch sử), ẩn/hiện.
- **Báo cáo tiến độ học viên**: thanh % hoàn thành, lọc theo lớp/từ khóa, teacher chỉ thấy lớp mình.
- **Bảng xếp hạng XP** (`Bảng xếp hạng`): Easy +10 / Medium +20 / Hard +30 XP cho lần giải đúng đầu tiên mỗi bài; không cộng lại khi AC lặp.
- **Hồ sơ cá nhân** (bấm tên mình trên navbar): XP, hạng, tiến độ %, verdict tốt nhất từng bài, 10 bài nộp gần nhất.
- **Tỉ lệ AC theo bài** trên trang Luyện tập: % người giải đúng / người đã thử.
- **Giám sát hàng đợi chấm** (`Bộ Test & Chấm lại → Hàng đợi chấm`, teacher/admin): bảng đếm QUEUED/RUNNING/SYS_ERROR/COMPLETED,
  cảnh báo run kẹt (quá 5 phút), nút **Thử lại** tạo lượt chấm `SYS_RETRY` mới mà không xóa lịch sử — người học không bị chấm sai vì lỗi hệ thống.
- **Chống chấm trùng**: bấm Chấm lại / Thử lại khi bài nộp đang có lượt chấm chờ xử lý sẽ bị từ chối (HTTP 409) thay vì tạo 2 run song song.
- **Fallback khi daemon chết**: API tự chấm trong request (vẫn qua tiến trình OS con riêng) nếu worker không heartbeat >60s, nên Chạy thử / Nộp bài không bao giờ kẹt QUEUED.
- **Tổng hợp kết quả** trên trang Tiến độ HV: thanh phân bố verdicts (AC/WA/TLE/RE/CE/SYS_ERR) theo phạm vi lớp đang xem.

## 7. Ghi chú nhóm & nguồn tái sử dụng

Xem [CONTRIBUTING.md](CONTRIBUTING.md) (phân công, quy trình issue/review) và
[THIRD_PARTY.md](THIRD_PARTY.md) (mã nhóm tự viết vs thư viện/tài nguyên dùng lại).
Giấy phép: [LICENSE](LICENSE) (MIT).
