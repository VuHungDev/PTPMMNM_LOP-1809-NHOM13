# AlgoJudge — Hệ thống Luyện tập & Chấm bài Lập trình Trực tuyến

Hệ thống luyện tập và chấm bài tự động, hỗ trợ 6 ngôn ngữ (C / C++ / Java /
Python / PHP / JS), chạy trên **XAMPP + MySQL** (Windows & Linux), soạn thảo
bằng **Monaco Editor**, kèm **trợ lý AI** hỗ trợ tạo bài và thao tác nhanh.

## 1. Kiến trúc

```text
AlgoJudge/
├── index.php                  → redirect về frontend/
├── backend/                   → XỬ LÝ + LƯU TRỮ (nhóm tự viết)
│   ├── config/bootstrap.php   → MySQL (thử cổng 3307 rồi 3306), hằng số, helper e()/url()
│   ├── config/ai.php          → cấu hình AI (provider, model, api_key — KHÔNG commit key thật)
│   ├── lib/Auth.php           → đăng nhập, phân quyền 3 vai trò (student/teacher/admin)
│   ├── lib/JudgeEngine.php    → điều phối chấm, so sánh output, tổng hợp (6 ngôn ngữ)
│   ├── lib/JudgeQueue.php     → hàng đợi + heartbeat + fallback khi daemon chết
│   ├── lib/AIHelper.php       → gọi AI (Gemini) bóc tách đề bài, chat tự do
│   ├── lib/AIAssistant.php    → định tuyến ý định, tra cứu số liệu, thao tác có xác nhận
│   ├── api/                   → submit, run_custom, save_draft, get_draft,
│   │                            submission_status, session_status, rejudge, retry_run,
│   │                            export_excel, ai_parse_problem, ai_create_problem, ai_chat
│   ├── worker/judge_daemon.php→ CLI worker chấm cách ly (không chạy trong Web)
│   ├── database/              → schema.sql, seed 10 + 60 bài, migrate_v2 → v6
│   ├── reference_solutions/   → mã đúng/sai đối chiếu các bài gốc
│   └── tests/                 → run_mandatory_tests.php (5 phép thử bắt buộc)
└── frontend/                  → GIAO DIỆN
    ├── index.php (lọc chủ đề/độ khó/ngôn ngữ + phân trang)
    ├── workspace.php (IDE, tự chọn ngôn ngữ gợi ý) / playground.php (sân chơi tự do)
    ├── submissions.php / leaderboard.php (BXH XP) / profile.php
    ├── instructor/            → problems, problem_form (wizard 3 bước + tự lưu nháp),
    │                            ai_chat, dataset_versions, queue, progress, rejudge
    ├── admin/ (users, user_form) / auth/ / partials/ (layout, sidebar, ai_widget)
    └── assets/css + assets/js (modules, pages, vendor/alpine.min.js)
```

## 2. Tài khoản demo (mật khẩu chung `123456`)

| Username    | Vai trò | Lớp        |
|-------------|---------|------------|
| `sv_son`    | student | CNTT-18-02 |
| `sv_phuong` | student | CNTT-18-02 |
| `sv_khaclop`| student | CNTT-18-01 |
| `gv_cntt`   | teacher | CNTT-18-02 |
| `admin`     | admin   | ALL        |

Đăng ký mới chỉ tạo được **student**; admin cấp tài khoản mọi vai trò trong
trang Quản trị (đổi vai trò/lớp, reset mật khẩu, **khóa/mở** tài khoản, xóa tài
khoản chưa có dữ liệu — tài khoản còn bài nộp chỉ được khóa để giữ lịch sử).

## 3. Cài đặt & chạy trên XAMPP (Windows)

**Bước 0 — Khởi động MySQL** (XAMPP Control Panel → MySQL → Start).
Project thử cổng `3307` trước rồi `3306`, user `root`, mật khẩu rỗng.

**Bước 1 — Khởi tạo CSDL & dữ liệu mẫu (60+ bài, hàng trăm test case):**

```bash
C:\xampp\mysql\bin\mysql.exe -u root < backend\database\schema.sql
C:\xampp\mysql\bin\mysql.exe -u root algojudge < backend\database\migrations\migrate_v2.sql
C:\xampp\mysql\bin\mysql.exe -u root algojudge < backend\database\migrations\migrate_v3.sql
C:\xampp\mysql\bin\mysql.exe -u root algojudge < backend\database\migrations\migrate_v4.sql
C:\xampp\mysql\bin\mysql.exe -u root algojudge < backend\database\migrations\migrate_v5.sql
C:\xampp\mysql\bin\mysql.exe -u root algojudge < backend\database\migrations\migrate_v6.sql
C:\xampp\php\php.exe backend\database\seeds\seed_10_problems.php
C:\xampp\php\php.exe backend\database\seeds\seed_60_problems.php
```

(Nếu đã có CSDL bản cũ: chạy các migrate còn thiếu rồi seed lại.)

Mỗi bài gắn 1 **ngôn ngữ gợi ý** (`suggested_lang`) để lọc ở trang Luyện tập;
IDE tự chọn sẵn ngôn ngữ đó nhưng học viên vẫn được nộp mọi ngôn ngữ.

**Bước 2 — Mở ứng dụng:** Apache (XAMPP) → Start, vào
`http://localhost/AlgoJudge/` (tự chuyển về `frontend/`).

**Bước 2b — Judge Daemon thường trực (khuyên dùng):** mở
`start-judge-daemon.bat` (chuột phải → Run), giữ cửa sổ mở trong buổi chấm.
Khi daemon sống, Chạy thử / Nộp bài phản hồi tức thì. Nếu quên mở, API tự
chấm fallback trong request (tiến trình OS con riêng, không eval trong Web)
nên không bao giờ kẹt QUEUED.

**Bước 3 — Toolchain chấm bài** (cài ngôn ngữ nào muốn chấm):
`python`, `g++`/`gcc`, `javac`/`java`, `node`. PHP dùng chính PHP của XAMPP.
Thiếu toolchain ngôn ngữ nào → bài nộp ngôn ngữ đó nhận `CE` kèm thông báo,
không ảnh hưởng ngôn ngữ khác.

**Bước 4 — Kiểm thử 5 phép thử bắt buộc:**

```bash
C:\xampp\php\php.exe backend\tests\run_mandatory_tests.php
```

**Bước 5 — (Tùy chọn) Bật AI thật:** điền key vào `backend/config/ai.php`
(`provider`, `model`, `api_key`). Chưa có key vẫn dùng được toàn bộ luồng
chatbot nhờ chế độ bóc tách tự động cục bộ.

## 4. Các luồng demo

**Học viên:** đăng nhập `sv_son/123456` → chọn bài → viết mã (1 trong 6 ngôn
ngữ) → Chạy thử → Nộp bài → theo dõi chấm → xem kết quả & mã đã nộp → hỏi
trợ lý AI ✨ về cách làm (chỉ gợi ý hướng, không đưa code giải).

**Giảng viên:** đăng nhập `gv_cntt/123456` → tạo bài bằng form wizard 3 bước
(tự lưu nháp, lỗi chỉ sửa ô sai) hoặc dán đề vào chatbot AI ✨ (Xem trước
trong form / Đẩy lên luôn) → Bộ test & phiên bản → Chấm lại (giữ lịch sử) →
Tiến độ học viên → Xuất Excel. Thao tác vặt (thêm test, ẩn/hiện bài, chấm
lại, xuất báo cáo) có thể nhờ chatbot làm hộ — luôn hỏi **Đồng ý/Hủy** trước.

## 5. Verdict & quy tắc đã công bố

`AC` (đúng hết) · `WA` (sai đáp án, kể cả sai biên) · `CE` (lỗi biên dịch/cú
pháp) · `RE` (lỗi khi chạy, exit code ≠ 0) · `TLE` (vượt giới hạn thời gian /
lặp vô hạn) · `SYS_ERR` (bộ thực thi bị ngắt — không chấm người học sai, chờ
thử lại).

- Gửi lại cùng yêu cầu nộp (cùng `idempotency_key`) → trả đúng bài nộp cũ,
  không tạo bản trùng.
- Kết quả lần chấm cũ về sau không ghi đè lần chấm mới (`latest_run_id`).
- Người A không đọc được mã riêng của B; API/giao diện không lộ test kín,
  đáp án hay thông tin kết nối bộ thực thi.
- Chatbot: đọc thoải mái, **ghi phải xác nhận** (token 10 phút); không xóa dữ
  liệu, không sửa điểm/XP, không đổi vai trò, không SQL tự do — mỗi việc một
  API cố định đã kiểm tra quyền.

## 6. Tính năng chính

- **Form tạo bài wizard 3 bước** (Thông tin → Nội dung đề → Test chấm): slug
  tự sinh từ tiêu đề, cài đặt nâng cao gấp gọn, validate từng bước, lỗi chỉ
  sửa ô sai, **tự lưu nháp** (localStorage + giữ lại khi submit lỗi).
- **Trợ lý AI bong bóng ✨** mọi trang: hỏi đáp cách dùng, tra cứu số liệu
  thật (sĩ số, hàng đợi chấm, bài khó nhất...), tạo bài từ đề dán vào, tạo tài
  khoản, thêm test case, bật/tắt bài, chấm lại, thử lại run, xuất báo cáo —
  thao tác ghi luôn có bước xác nhận.
- **Quản lý bài tập (CRUD):** tạo bài, sửa (tự tạo phiên bản mới giữ lịch sử),
  ẩn/hiện; bộ test nhiều ca (công khai/kín, dữ liệu biên).
- **Báo cáo tiến độ học viên:** % hoàn thành, lọc lớp/từ khóa, teacher chỉ
  thấy lớp mình, phân bố verdicts, xuất Excel.
- **Bảng xếp hạng XP:** Easy +10 / Medium +20 / Hard +30 cho lần AC đầu tiên
  mỗi bài; không cộng lại khi AC lặp. Hồ sơ cá nhân: XP, hạng, verdict tốt
  nhất từng bài, 10 bài nộp gần nhất; tỉ lệ AC theo bài ở trang Luyện tập.
- **Chống chấm trùng & fallback daemon:** bấm Chấm lại/Thử lại khi đang có
  lượt chấm chờ xử lý bị từ chối (HTTP 409); daemon chết >60s không heartbeat
  → API tự chấm đồng bộ; cảnh báo run kẹt quá 5 phút.
- **Phân trang + xuất Excel (.xls)** mọi danh sách dài, đúng bộ lọc hiện tại.

## 7. Tài liệu nhóm

Xem [CONTRIBUTING.md](CONTRIBUTING.md) (phân công, quy trình issue/review, quy
tắc code) và [THIRD_PARTY.md](THIRD_PARTY.md) (mã tự viết vs thư viện dùng
lại). Giấy phép: [LICENSE](LICENSE) (MIT).
