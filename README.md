# AlgoJudge

**Nền tảng luyện tập và chấm bài lập trình trực tuyến dành cho học viên, giảng viên và quản trị viên.**

AlgoJudge cung cấp môi trường viết mã trên trình duyệt, chạy thử với dữ liệu tự nhập, nộp bài và theo dõi kết quả chấm tự động. Hệ thống hỗ trợ **6 ngôn ngữ**, quản lý lớp học, lưu lịch sử bài nộp, thống kê tiến độ và trợ lý AI hỗ trợ soạn bài tập.

Ứng dụng sử dụng **PHP + MariaDB/MySQL** trên XAMPP, **Monaco Editor** cho trình soạn thảo và **Docker Linux containers** để cách ly cả biên dịch lẫn thực thi mã của người học.

> **Quan trọng:** Bộ chấm hiện tại bắt buộc dùng Docker. Cài trình biên dịch trên Windows là chưa đủ. Không chạy lại script seed hoặc khởi tạo schema trên cơ sở dữ liệu đang sử dụng để “sửa lỗi”, vì có thể làm mất dữ liệu học tập.

## Mục lục

- [1. Tính năng chính](#1-tính-năng-chính)
- [2. Công nghệ và kiến trúc](#2-công-nghệ-và-kiến-trúc)
- [3. Yêu cầu môi trường](#3-yêu-cầu-môi-trường)
- [4. Cài đặt trên Windows với XAMPP](#4-cài-đặt-trên-windows-với-xampp)
- [5. Cấu hình AI và email](#5-cấu-hình-ai-và-email)
- [6. Tài khoản và luồng sử dụng mẫu](#6-tài-khoản-và-luồng-sử-dụng-mẫu)
- [7. Quy tắc chấm bài](#7-quy-tắc-chấm-bài)
- [8. Phân quyền và bảo vệ dữ liệu](#8-phân-quyền-và-bảo-vệ-dữ-liệu)
- [9. Nâng cấp cơ sở dữ liệu](#9-nâng-cấp-cơ-sở-dữ-liệu)
- [10. Kiểm thử](#10-kiểm-thử)
- [11. Xử lý sự cố thường gặp](#11-xử-lý-sự-cố-thường-gặp)
- [12. Tài liệu và giấy phép](#12-tài-liệu-và-giấy-phép)

## 1. Tính năng chính

### Học viên

- Tìm và lọc bài theo chủ đề, độ khó và ngôn ngữ gợi ý; ngôn ngữ gợi ý không giới hạn ngôn ngữ được nộp.
- Viết mã bằng Monaco Editor, xem đề và ví dụ, chạy thử với `stdin` tự nhập trước khi nộp.
- Lưu nháp theo tài khoản, bài tập và ngôn ngữ; có cơ chế hạn chế tab cũ ghi đè bản nháp mới.
- Xem kết quả từng lần chấm, lịch sử bài nộp và mã nguồn của chính mình.
- Sử dụng **Playground** để chạy chương trình ngoài bài tập, không cộng XP hay tạo bài nộp chính thức.
- Xin tham gia lớp bằng mã mời và theo dõi trạng thái duyệt.
- Xem hồ sơ, XP và bảng xếp hạng; tự đăng ký tài khoản học viên và khôi phục mật khẩu qua OTP email khi đã cấu hình SMTP.

### Giảng viên

- Tạo bài bằng biểu mẫu từng bước; quản lý nội dung, giới hạn tài nguyên và test công khai/test kín.
- Tạo phiên bản mới của đề và bộ test; chấm lại theo phiên bản được chọn mà vẫn lưu lịch sử các lượt chấm.
- Quản lý lớp được giao chủ quản: mã mời, yêu cầu tham gia và danh sách học viên.
- Theo dõi tiến độ, bài nộp của học viên thuộc lớp mình; xem hàng đợi chấm và xuất báo cáo theo bộ lọc.
- Dùng trợ lý AI để soạn, điều chỉnh và xem trước bản nháp bài tập; chỉ lưu bài sau khi xác nhận bằng thao tác trên giao diện.

### Quản trị viên

- Quản lý tài khoản học viên, giảng viên và phân công chủ quản lớp.
- Khóa/mở tài khoản, quản lý ngân hàng bài tập và theo dõi hoạt động hệ thống.
- Xem nhật ký thao tác quản trị của chính tài khoản đang đăng nhập.

## 2. Công nghệ và kiến trúc

| Thành phần | Công nghệ / vai trò |
| --- | --- |
| Web server | Apache trong XAMPP |
| Backend | PHP, PDO, session và các API PHP |
| Cơ sở dữ liệu | MariaDB/MySQL, InnoDB, `utf8mb4` |
| Frontend | Trang PHP render phía máy chủ, CSS, JavaScript ES modules, Alpine.js |
| Trình soạn thảo | Monaco Editor, tải từ CDN |
| Bộ chấm | PHP điều phối hàng đợi và Docker Linux containers |
| AI | Gemini hoặc API tương thích OpenAI Chat Completions; phản hồi chat qua SSE |
| Email | PHPMailer qua SMTP, cài bằng Composer |

### Luồng chấm bài

```text
Trình duyệt → API → Cơ sở dữ liệu / hàng đợi
                          ↓
                  Judge daemon hoặc
                 xử lý dự phòng từ API
                          ↓
                  DockerSandbox
              biên dịch → chạy từng test
                          ↓
              So sánh output trong PHP
                          ↓
              Lưu kết quả → Trình duyệt
```

Daemon và cơ chế xử lý dự phòng đều dùng cùng sandbox Docker. Nếu Docker, image hoặc cấu hình cách ly không hợp lệ, hệ thống trả lỗi hạ tầng `SYS_ERR`, **không chuyển sang chạy mã trực tiếp trên máy chủ**.

### Cấu trúc thư mục

```text
AlgoJudge/
├── index.php                       # Điểm vào, chuyển đến frontend
├── .env.example                    # Mẫu cấu hình môi trường, không chứa bí mật thật
├── .htaccess                       # Chặn truy cập HTTP vào tệp và thư mục nội bộ
├── composer.json                   # Phụ thuộc PHP
├── start-mysql3307.bat              # Launcher cho cấu hình XAMPP 3307/data3307 có sẵn
├── start-judge-daemon.bat           # Khởi động worker chấm trên Windows
├── backend/
│   ├── config/                     # Bootstrap, cấu hình AI và image sandbox
│   ├── api/                        # Nộp bài, chạy thử, nháp, kết quả, báo cáo, AI
│   ├── lib/                        # Phân quyền, lớp học, bộ chấm, OTP, chatbot...
│   ├── database/
│   │   ├── schema.sql              # Schema cài mới, đã tích hợp v2–v10
│   │   ├── migrations/             # Cơ sở dữ liệu
│   │   └── seeds/                  # Dữ liệu mẫu và tiện ích dữ liệu
│   ├── worker/                     # Judge daemon chạy bằng PHP CLI
│   ├── sandbox/docker/             # Dockerfile, mẫu image và hướng dẫn vận hành
│   ├── reference_solutions/        # Mã tham chiếu phục vụ đối chiếu
│   └── tests/                      # Kiểm thử offline, tích hợp, HTTP và trình duyệt
└── frontend/
    ├── index.php                   # Danh sách bài luyện tập
    ├── workspace.php               # Làm bài và nộp bài
    ├── playground.php              # Chạy mã tự do
    ├── submissions.php             # Lịch sử bài nộp
    ├── leaderboard.php             # Bảng xếp hạng
    ├── profile.php                 # Hồ sơ cá nhân
    ├── auth/                       # Đăng nhập, đăng ký, OTP
    ├── instructor/                 # Bài tập, bộ test, lớp học, tiến độ, hàng đợi
    ├── admin/                      # Quản lý tài khoản và lớp học
    ├── partials/                   # Thành phần giao diện dùng chung
    └── assets/                     # CSS, JavaScript và tài nguyên giao diện
```

## 3. Yêu cầu môi trường

Hướng dẫn bên dưới dành cho **Windows + XAMPP** tại `C:\xampp`, dự án đặt trong `C:\xampp\htdocs\AlgoJudge`.

| Thành phần | Yêu cầu |
| --- | --- |
| PHP | PHP 8.0 trở lên; bản XAMPP hiện dùng PHP 8.0.30 |
| Cơ sở dữ liệu | MariaDB/MySQL; các migration mới sử dụng cú pháp MariaDB 10.4 |
| PHP extensions | `pdo_mysql`, `curl`, `openssl`; nên bật `mbstring` |
| Composer | Cài phụ thuộc PHP theo `composer.lock` |
| Docker | Docker Desktop ở chế độ **Linux containers**, có seccomp và giới hạn CPU, RAM/swap, PID |
| Image chấm | Image Linux đã được kiểm tra, có sẵn cục bộ và được cố định bằng SHA-256 |
| Trình duyệt | Trình duyệt hiện đại; cần kết nối CDN để tải Monaco Editor |
| Node.js | Chỉ cần trên máy phát triển nếu chạy các bộ kiểm thử JavaScript |

Các compiler/interpreter phải có **trong image Docker** tương ứng:

| Ngôn ngữ | Mã cấu hình | Công cụ và quy ước |
| --- | --- | --- |
| C | `c` | `gcc`, chuẩn C11 |
| C++ | `cpp` | `g++`, chuẩn C++17 |
| Java | `java` | `javac` và `java`; chương trình có lớp `Main` |
| Python | `python` | `python3`, chạy ở chế độ cách ly |
| PHP | `php` | PHP CLI trong container, chạy với `-n` |
| JavaScript | `js` | Node.js trong container |

Thư viện Python/npm đã cài trên Windows hoặc trong thư mục dự án **không tự xuất hiện trong container**. Thư viện bổ sung phải được đưa vào image đã kiểm tra trước khi triển khai.

## 4. Cài đặt trên Windows với XAMPP

> Các khối lệnh trong mục này dùng **PowerShell**. Chạy tại thư mục gốc dự án. Nếu cài XAMPP ở vị trí khác, thay các đường dẫn tương ứng.

### Bước 1 — Chuẩn bị mã nguồn và phụ thuộc

Đặt dự án tại `C:\xampp\htdocs\AlgoJudge`, sau đó mở PowerShell:

```powershell
Set-Location "C:\xampp\htdocs\AlgoJudge"
composer install
```

Composer cần được cấu hình dùng PHP của XAMPP. Nếu chưa có lệnh `composer`, cài Composer và chọn `C:\xampp\php\php.exe` làm trình thực thi PHP.

Tạo cấu hình môi trường **chỉ khi chưa có `.env`**:

```powershell
if (-not (Test-Path ".env")) { Copy-Item ".env.example" ".env" }
```

Nếu đã có `.env`, chỉ bổ sung biến còn thiếu, không ghi đè khóa hoặc mật khẩu đang dùng. Không đưa `.env` vào kho mã nguồn.

### Bước 2 — Khởi động và cấu hình cơ sở dữ liệu

Khởi động MySQL/MariaDB trong XAMPP. Thiết lập kết nối trong `.env` theo instance thực tế:

```dotenv
AJ_DB_HOST=127.0.0.1
AJ_DB_PORT=3307
AJ_DB_NAME=algojudge
AJ_DB_USER=root
AJ_DB_PASS=
```

- Nếu để trống `AJ_DB_PORT`, ứng dụng thử **3307**, sau đó **3306**.
- Nếu máy dùng cổng `3306`, đổi cấu hình và các lệnh import bên dưới sang `3306`.
- Tài khoản `root` với mật khẩu rỗng chỉ phù hợp môi trường demo cục bộ. Khi triển khai, dùng tài khoản CSDL riêng với quyền tối thiểu cần thiết.
- `start-mysql3307.bat` dành riêng cho máy đã có cấu hình `C:\xampp\mysql\data3307`; script không khởi tạo dữ liệu mới và không thay thế bước cài MariaDB. Có thể dùng `./start-mysql3307.bat /check` để kiểm tra mà không khởi động server.

### Bước 3 — Khởi tạo schema cho bản cài mới

**Chỉ chạy khi chưa có cơ sở dữ liệu AlgoJudge cần giữ lại.** Schema tạo database tên `algojudge` và đã bao gồm cấu trúc v2–v10; không cần chạy lần lượt các migration cũ sau đó.

```powershell
& "C:\xampp\mysql\bin\mysql.exe" --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --default-character-set=utf8mb4 --execute="source C:/xampp/htdocs/AlgoJudge/backend/database/schema.sql"
```

Nếu tài khoản CSDL có mật khẩu, thêm `--password` để CLI hỏi mật khẩu, không ghi mật khẩu thật trực tiếp vào lệnh. Nếu đã có dữ liệu, chuyển đến [mục nâng cấp](#9-nâng-cấp-cơ-sở-dữ-liệu), không import lại schema.

### Bước 4 — Nạp dữ liệu demo, nếu cần

> **CẢNH BÁO MẤT DỮ LIỆU:** `seed_10_problems.php` dùng `TRUNCATE` để xóa tài khoản, bài tập, bản nháp, bài nộp và lịch sử chấm trước khi tạo dữ liệu mẫu. Chỉ dùng ngay sau bước tạo schema trên CSDL demo mới, chưa có dữ liệu cần giữ. Không chạy trên CSDL đang sử dụng.

```powershell
& "C:\xampp\php\php.exe" backend\database\seeds\seed_10_problems.php
& "C:\xampp\php\php.exe" backend\database\seeds\seed_60_problems.php
```

Hai script tạo tổng cộng **60 bài tập mẫu**: 10 bài gốc và 50 bài bổ sung. Script thứ hai bỏ qua slug đã tồn tại; điều này **không** làm script thứ nhất an toàn để chạy lại.

Seed hiện ghi thông tin lớp kiểu cũ qua `class_code`. Để đưa lớp và thành viên mẫu vào mô hình `classes`/`class_members`, chạy bước nhập lớp sau khi seed:

```powershell
& "C:\xampp\mysql\bin\mysql.exe" --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --default-character-set=utf8mb4 algojudge --execute="source C:/xampp/htdocs/AlgoJudge/backend/database/migrations/migrate_v8_classes.sql"
```

Đây là bước nhập **lớp demo sau seed**, không phải yêu cầu chạy lại toàn bộ migration cho bản cài mới.

Nếu không dùng dữ liệu demo, chuẩn bị tài khoản admin ban đầu bằng quy trình quản trị CSDL tin cậy; không dùng seed phá dữ liệu như một công cụ tạo thêm tài khoản.

### Bước 5 — Chuẩn bị sandbox Docker

1. Mở Docker Desktop và chờ Linux engine sẵn sàng.
2. Chuẩn bị image Linux đã kiểm tra, có toolchain của từng ngôn ngữ và các tiện ích hệ thống bắt buộc.
3. Lấy **image ID đầy đủ** bằng `docker image inspect`, hoặc dùng repository digest bất biến.
4. Nếu chưa có cấu hình, sao chép mẫu:

```powershell
if (-not (Test-Path "backend\config\sandbox_images.php")) { Copy-Item "backend\sandbox\docker\sandbox_images.example.php" "backend\config\sandbox_images.php" }
```

5. Điền image ID/digest cho các khóa `c`, `cpp`, `java`, `python`, `php`, `js` trong tệp vừa tạo. Có thể đặt `docker_binary` thành đường dẫn tuyệt đối của Docker CLI nếu Apache không tìm thấy `docker.exe`.

Giá trị image hợp lệ có dạng `sha256:<64 ký tự hex thường>` hoặc `repository@sha256:<64 ký tự hex thường>`. **Không dùng tag thay thế digest**, chẳng hạn `python:latest`.

Có thể ghi đè từng image bằng `AJ_SANDBOX_IMAGE_C`, `AJ_SANDBOX_IMAGE_CPP`, `AJ_SANDBOX_IMAGE_JAVA`, `AJ_SANDBOX_IMAGE_PYTHON`, `AJ_SANDBOX_IMAGE_PHP`, `AJ_SANDBOX_IMAGE_JS`; `AJ_SANDBOX_DOCKER` ghi đè đường dẫn Docker CLI. Đảm bảo Apache và worker cùng nhìn thấy engine và cấu hình này.

Ứng dụng không tự pull image hoặc cài gói khi chấm. Dockerfile được cung cấp chỉ bọc một base image đã có sẵn, không tự cài compiler. Xem công thức build với build context hẹp và yêu cầu image tại:

- [Hướng dẫn sandbox Docker](backend/sandbox/docker/README.txt)
- [Hướng dẫn vận hành chi tiết](backend/sandbox/docker/OPERATIONS.txt)
- [Mẫu cấu hình image](backend/sandbox/docker/sandbox_images.example.php)

### Bước 6 — Mở ứng dụng và worker

Khởi động **Apache** trong XAMPP, rồi truy cập:

**<http://localhost/AlgoJudge/>**

Chạy worker trong một cửa sổ riêng và giữ cửa sổ mở:

```powershell
.\start-judge-daemon.bat
```

Hoặc chạy trực tiếp nếu môi trường Docker đã được cấu hình:

```powershell
& "C:\xampp\php\php.exe" backend\worker\judge_daemon.php
```

Worker gửi heartbeat để API nhận biết trạng thái. Khi worker không sẵn sàng, API có cơ chế xử lý dự phòng, nhưng vẫn cần Docker hoạt động và có thể phải chờ request lâu hơn. Nên duy trì daemon trong suốt buổi luyện tập.

Nếu đổi tên thư mục dự án hoặc triển khai dưới URL khác, cập nhật `BASE_URL` trong [bootstrap.php](backend/config/bootstrap.php). Sau khi thay đổi mã bộ chấm hoặc cấu hình, khởi động lại worker; khởi động lại Apache khi cần để tránh cấu hình/mã cũ còn trong tiến trình hoặc cache.

## 5. Cấu hình AI và email

### Trợ lý AI — tùy chọn

Cấu hình trong `.env`, không ghi khóa thật vào [ai.php](backend/config/ai.php):

| Biến | Ý nghĩa |
| --- | --- |
| `AI_PROVIDER` | `gemini` hoặc `openai` cho API tương thích Chat Completions |
| `AI_MODEL` | Tên model hợp lệ với tài khoản và nhà cung cấp đang dùng |
| `GEMINI_API_KEY` | Khóa khi dùng Gemini |
| `OPENAI_API_KEY` | Khóa khi dùng nhánh API tương thích OpenAI |
| `AI_BASE_URL` | Base URL của nhánh tương thích OpenAI |
| `AI_TIMEOUT` | `0`: không đặt giới hạn chờ ở lớp gọi AI; số dương được giới hạn trong khoảng 5–180 giây |

AI hỗ trợ hỏi đáp, tra cứu theo quyền và soạn bản nháp bài tập. Người dùng cần kiểm tra nội dung, ví dụ và test trước khi lưu. Chatbot không phải một giao diện thực thi tùy ý: không coi câu lệnh bằng văn bản là quyền tự động xóa tài khoản, tạo lớp, chấm lại hoặc sửa bài đã lưu.

Đăng nhập, luyện tập và chấm bài không phụ thuộc khóa AI. Các chức năng gọi model phụ thuộc cấu hình, kết nối mạng và tình trạng dịch vụ nhà cung cấp. Nội dung gửi tới model được chuyển cho dịch vụ bên ngoài; không đưa mật khẩu, khóa API hoặc dữ liệu nhạy cảm vào hội thoại.

### Email OTP — cần cho khôi phục mật khẩu

Cài PHPMailer qua Composer và điền các biến trong `.env`:

| Nhóm | Biến |
| --- | --- |
| SMTP server | `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION` |
| Xác thực | `MAIL_USERNAME`, `MAIL_PASSWORD` |
| Người gửi | `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` |
| Bảo vệ OTP | `OTP_HASH_KEY`: chuỗi bí mật ngẫu nhiên ít nhất 32 ký tự, không dùng chung mật khẩu email |

Thông thường dùng `tls` với cổng `587`, hoặc `ssl` với cổng `465`, theo nhà cung cấp email. Với Gmail, tài khoản cần bật xác minh hai bước và dùng mật khẩu ứng dụng khi dịch vụ yêu cầu.

Tài khoản demo dùng email mẫu nên không nhận được email thật. Luồng quên mật khẩu tự phục vụ không áp dụng cho admin.

## 6. Tài khoản và luồng sử dụng mẫu

Các tài khoản sau chỉ tồn tại sau khi nạp seed demo. **Mật khẩu chung: `123456`.**

| Tên đăng nhập | Vai trò | Lớp mẫu sau bước nhập lớp |
| --- | --- | --- |
| `admin` | Admin  | — |
| `gv_cntt` | Giảng viên | Chủ quản CNTT-18-02 |
| `sv_son` | Học viên | CNTT-18-02 |
| `sv_phuong` | Học viên | CNTT-18-02 |
| `sv_khaclop` | Học viên | CNTT-18-01 |

Không sử dụng mật khẩu demo khi đưa hệ thống ra ngoài môi trường cục bộ.

- **Học viên:** đăng nhập → chọn bài → viết mã → nhập dữ liệu chạy thử → nộp bài → xem kết quả và lịch sử.
- **Giảng viên:** tạo bài hoặc soạn nháp bằng AI → kiểm tra test → lưu → quản lý lớp/học viên → xem tiến độ và xuất báo cáo.
- **Admin:** quản lý tài khoản → phân công lớp → theo dõi hệ thống; admin chính cấp quyền admin phụ theo chính sách.

## 7. Quy tắc chấm bài

### Trạng thái và verdict

| Mã | Ý nghĩa |
| --- | --- |
| `QUEUED` | Yêu cầu đang chờ chấm |
| `RUNNING` | Đang chấm |
| `AC` | Vượt qua toàn bộ test |
| `WA` | Output không khớp đáp án |
| `CE` | Lỗi biên dịch, kiểm tra cú pháp hoặc vượt tài nguyên ở bước biên dịch |
| `RE` | Lỗi thực thi, exit code khác 0, vượt bộ nhớ hoặc giới hạn output |
| `TLE` | Vượt giới hạn thời gian |
| `SYS_ERR` | Lỗi hạ tầng/cấu hình bộ chấm; không phải kết luận thuật toán sai |

Hệ thống hiện không có verdict `MLE`/`OLE` riêng; các trường hợp tương ứng được biểu diễn bằng `RE` kèm thông báo. Bản ghi lượt chấm có thể mang trạng thái nội bộ `SYS_ERROR` khi lỗi hạ tầng.

### Hợp đồng đầu vào/đầu ra

- Chương trình **một tệp**, đọc từ standard input và ghi đáp án ra standard output. Không có console nhập tương tác trong lúc chương trình chạy.
- Java cần lớp `Main`; JavaScript chạy trên Node.js, không phải môi trường DOM của trình duyệt.
- Không in lời nhắc nhập, nhãn kết quả hoặc thông tin debug vào `stdout` nếu đề không yêu cầu.
- Bộ so sánh chuẩn hóa khoảng trắng ở đầu/cuối toàn bộ output và cuối mỗi dòng; không tự bỏ nhãn, đổi thứ tự dòng hay áp dụng dung sai số thực.
- Mỗi test chạy trong container mới; test kín không được trả input, expected output hoặc actual output qua API kết quả của học viên.

### Giới hạn hiện tại

| Hạng mục | Giới hạn |
| --- | --- |
| Mã nguồn | 256 KiB |
| `stdin` qua API chạy thử | 60.000 byte |
| `stdin` của một test qua sandbox | 1 MiB |
| Output thu nhận | `stdout` 64 KiB, `stderr` 32 KiB |
| Biên dịch | 30 giây, RAM 512 MiB |
| Chạy thử / Playground | 10 giây, RAM 256 MiB |
| Chấm chính thức | Theo đề; thời gian 1–60.000 ms, bộ nhớ 16–1024 MiB |

Java dùng giới hạn bộ nhớ đề làm heap và có thêm 128 MiB ở giới hạn container cho phần ngoài heap của JVM. Thời gian ghi nhận là thời gian thực thi theo đồng hồ, không phải phép đo CPU thuần.

### Lịch sử và XP

- `idempotency_key` giúp yêu cầu nộp lặp không tạo bài nộp trùng.
- Bài nộp gắn với phiên bản đề tại thời điểm gửi. Chấm lại/thử lại tạo **lượt chấm mới**, giữ mã nguồn và kết quả lịch sử.
- `latest_run_id` ngăn kết quả đến muộn của lượt cũ ghi đè lượt mới.
- Lần AC đầu tiên cho mỗi người/bài thưởng **Easy: 10 XP · Medium: 20 XP · Hard: 30 XP**; AC lặp không thưởng lại.
- Playground không tính XP hay tạo bài nộp chính thức, nhưng mã nguồn và dữ liệu chạy thử vẫn được lưu trong `custom_runs` trên máy chủ.

## 8. Phân quyền và bảo vệ dữ liệu

- **Học viên:** chỉ xem mã nguồn và chi tiết bài nộp của mình.
- **Giảng viên:** xem bài của mình và của học viên có tư cách thành viên `active` trong lớp mình chủ quản; yêu cầu tham gia đang chờ duyệt không cấp quyền này.
- **Admin:** có phạm vi xem toàn hệ thống. Admin phụ không được quản lý admin chính hoặc admin ngang hàng; chỉ admin chính quản lý admin phụ.
- Bảng xếp hạng tổng hợp toàn hệ thống không mở rộng quyền xem mã nguồn, bài nộp hoặc báo cáo chi tiết ngoài phạm vi lớp của giảng viên.
- Ngân hàng bài tập được quản lý theo vai trò giảng viên/admin; không đồng nhất phạm vi ngân hàng đề với phạm vi dữ liệu học viên theo lớp.
- Admin chính được xác định bằng `AJ_PRIMARY_ADMIN_ID` trong môi trường, mặc định là `1`; cấp role `admin` cho tài khoản khác không làm tài khoản đó trở thành admin chính.

Khi triển khai:

- Giữ `.env`, cấu hình, schema, test, worker và thư viện nội bộ ngoài phạm vi truy cập HTTP. Apache cần cho phép áp dụng `.htaccess` và bật `mod_rewrite`; xác minh các đường dẫn nội bộ thực sự bị chặn.
- Bảo vệ quyền sửa mã nguồn, cấu hình image và quyền truy cập Docker; không đưa Docker socket hay thư mục ứng dụng vào container chạy bài.
- Sandbox dùng user không đặc quyền, tắt mạng, root filesystem chỉ đọc và giới hạn tài nguyên. Đây không phải cam kết chống mọi lỗi kernel/container; nên dùng máy chấm riêng cho triển khai có người dùng không tin cậy.
- Sao lưu trước khi nâng cấp. Không sửa mã nguồn, verdict hoặc dữ liệu lịch sử để làm một bài nộp cũ “đạt”; nếu cần đánh giá lại, tạo lượt chấm mới.

## 9. Cơ sở dữ liệu

Thư mục [migrations](backend/database/migrations/) chứa các  CSDL

| Migration | Nội dung chính |
| --- | --- |
| `migrate_v2.sql` | Sáu ngôn ngữ, chuẩn hóa vai trò `teacher` |
| `migrate_v3.sql` | Ngôn ngữ gợi ý của bài tập |
| `migrate_v4.sql` | Khóa/mở tài khoản |
| `migrate_v5.sql` | Heartbeat của judge daemon |
| `migrate_v6.sql` | Thông tin lỗi chạy thử |
| `migrate_v7_password_reset.sql` | OTP khôi phục mật khẩu và phiên đăng nhập |
| `migrate_v8_classes.sql` | Lớp, thành viên, nhập dữ liệu lớp cũ |
| `migrate_v9_profile_audit.sql` | Ngữ cảnh OTP và nhật ký quản trị |
| `migrate_v10_chatbot.sql` | Phiên chat, bản nháp và ghi nhận xác nhận tạo bài |

Quy trình: **sao lưu → xác định cấu trúc hiện có → đọc và chạy các migration còn thiếu theo thứ tự → kiểm tra → khởi động lại tiến trình cần thiết**.

Không chạy mù toàn bộ migration: một số file dùng `ADD COLUMN` không có `IF NOT EXISTS`. Không nạp lại `schema.sql`, không chạy lại seed để nâng cấp và không dùng `class_code` thay cho membership khi kiểm tra quyền.

## 10. Kiểm thử

Chạy từ thư mục gốc dự án. Các bộ dưới đây dùng dữ liệu giả lập hoặc kiểm tra tĩnh, không yêu cầu CSDL làm việc:

```powershell
& "C:\xampp\php\php.exe" backend\tests\lint_php.php
& "C:\xampp\php\php.exe" backend\tests\admin_policy_tests.php
& "C:\xampp\php\php.exe" backend\tests\problem_draft_tests.php
& "C:\xampp\php\php.exe" backend\tests\chatbot_routing_tests.php
& "C:\xampp\php\php.exe" backend\tests\workspace_draft_api_tests.php
& "C:\xampp\php\php.exe" backend\tests\run_submit_api_contract_tests.php
node backend\tests\workspace_output_tests.mjs
node --test backend\tests\chatbot_frontend_tests.cjs
```

Kiểm thử sandbox cần Docker và image được cấu hình riêng cho tiến trình test theo [OPERATIONS.txt](backend/sandbox/docker/OPERATIONS.txt):

```powershell
& "C:\xampp\php\php.exe" backend\tests\docker_sandbox_smoke.php
& "C:\xampp\php\php.exe" backend\tests\docker_sandbox_isolation_tests.php
```

Các bộ tích hợp, HTTP và trình duyệt có thể tạo schema, ghi tài khoản/bài tập/bài nộp và giữ fixture sau khi chạy. Đọc điều kiện trong từng script, dùng **CSDL thử nghiệm tách biệt**; một số bộ bắt buộc tên schema hoặc cổng cụ thể. Không xem mọi file trong `backend/tests` là lệnh có thể chạy an toàn trên CSDL đang sử dụng.

Đặc biệt, `run_mandatory_tests.php` ghi trực tiếp vào CSDL đang cấu hình và dùng ID mẫu cố định. **Không chạy bộ này trên dữ liệu thật.**

## 11. Xử lý sự cố thường gặp

| Hiện tượng | Kiểm tra / cách xử lý |
| --- | --- |
| Không kết nối được MySQL | Kiểm tra dịch vụ, đúng instance/cổng, `AJ_DB_*` và sự tồn tại của database. Không khởi tạo lại dữ liệu chỉ để thử kết nối. |
| Thiếu bảng hoặc cột sau cập nhật | Đối chiếu schema hiện có với migration còn thiếu; sao lưu trước khi chạy. |
| Mọi bài chạy đều `SYS_ERR` | Kiểm tra Docker Linux engine, image pin và image đã có cục bộ; xác minh Apache/worker truy cập được Docker CLI và engine. |
| CLI chấm được nhưng web không chấm được | Kiểm tra PATH, quyền chạy dịch vụ và `docker_binary`/`AJ_SANDBOX_DOCKER`; Apache có thể dùng môi trường khác PowerShell. |
| Bài ở trạng thái chờ lâu | Kiểm tra cửa sổ daemon, heartbeat, Docker và hàng đợi; không sửa verdict trực tiếp trong SQL. |
| Gói Python/npm không tìm thấy | Kiểm tra thư viện đã có trong image Linux, không chỉ có trên Windows; sandbox không cài gói qua mạng khi chạy. |
| Không thấy editor | Kiểm tra kết nối CDN, lỗi JavaScript và đường dẫn ứng dụng. |
| Không nhận OTP | Kiểm tra Composer/PHPMailer, SMTP, email thật, mật khẩu ứng dụng, `OTP_HASH_KEY` và thư rác; admin không dùng luồng này. |
| AI không phản hồi | Kiểm tra provider, model, khóa trong `.env`, mạng và timeout; không đưa khóa vào log hay ảnh chụp lỗi. |
| Giáo viên không thấy học viên | Kiểm tra lớp được giao chủ quản và membership `active`; `class_code` cũ không tự cấp quyền. |
| Excel cảnh báo định dạng | Bản xuất hiện là bảng HTML đuôi `.xls`, không phải tệp `.xlsx` native. |

## 12. Tài liệu và giấy phép

- [Mẫu biến môi trường](.env.example)
- [Schema cài mới](backend/database/schema.sql)
- [Các migration](backend/database/migrations/)
- [Cấu hình và vận hành Docker judge](backend/sandbox/docker/OPERATIONS.txt)
- [Thành phần bên thứ ba](THIRD_PARTY.md)
- [Giấy phép MIT của dự án](LICENSE)

Thành phần bên thứ ba và dịch vụ AI tuân theo giấy phép/điều khoản riêng; giấy phép MIT của dự án không thay thế các điều khoản đó. Khả năng thực thi và thư viện thực tế của bộ chấm phụ thuộc image Docker đã triển khai, không phụ thuộc danh sách gói cài trên máy Windows.
