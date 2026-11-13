# Bộ Mã Nguồn Đối Chiếu (Đúng & Sai) Cho 10 Bài Tập (Mục 5)

Thư mục này chứa các lời giải chuẩn (`AC`) và các mã nguồn sai biên (`WA`), lặp vô hạn (`TLE`), lỗi biên dịch (`CE`), lỗi thực thi (`RE`) bằng cả **Python 3** và **C++17** cho 10 bài tập trong hệ thống.

## Giai thừa: chương trình Python chạy độc lập

`python_factorial.py` đọc một số nguyên từ stdin và chỉ in giá trị giai thừa ra stdout. Không in lời nhắc nhập, biểu thức `N! = ...` hoặc thông tin debug nếu đề bài không yêu cầu.

```text
stdin:  5
stdout: 120
```

Đây là lời giải đối chiếu để chạy thử, không tự thay thế bản nháp của người học hoặc thay đổi lịch sử bài nộp.

## Kiểm thử định dạng đầu ra

Từ thư mục gốc dự án, chạy:

```powershell
& 'C:/xampp/php/php.exe' backend/tests/judge_output_tests.php
node backend/tests/workspace_output_tests.mjs
```

Hai bộ kiểm thử này không khởi tạo ứng dụng, không cần MySQL và không thay đổi database. Bộ PHP cần Python 3 (có thể chỉ định đường dẫn bằng biến `AJ_TEST_PYTHON`) để kiểm tra chương trình giai thừa với mọi `N` từ `0` đến `20`; bộ JavaScript cần Node.js.

Máy chấm vẫn so sánh chính xác sau chuẩn hóa khoảng trắng theo quy tắc hiện có. Lời nhắc nhập, nhãn kết quả, số thừa hoặc đáp án sai vẫn phải bị `WA`; không tự lọc chữ thừa để biến bài sai thành `AC`.

### Kiểm thử trình duyệt tích hợp trên dữ liệu riêng

```powershell
node backend/tests/browser_workspace_output_smoke.mjs
```

Script này dành cho môi trường Windows/XAMPP hiện tại: PHP tại `C:/xampp/php/php.exe`, Chrome tại `C:/Program Files/Google/Chrome/Application/chrome.exe`, Node.js hỗ trợ `fetch` và `WebSocket`, MariaDB tại `127.0.0.1:3307`. Cổng `8091` và `9231` phải đang trống; script không dừng dịch vụ có sẵn.

Mỗi lần chạy tạo một schema **mới** mang tiền tố `algojudge_test_workspace_`, từ chối ghi đè schema đã tồn tại và chỉ dùng tài khoản/dữ liệu tổng hợp. Script không sửa database làm việc, không đọc `.env` của dự án; các tiến trình PHP thử nghiệm dùng cấu hình riêng. Schema thử, profile Chrome, log và ảnh chụp được giữ lại để kiểm tra, không tự xóa. Xem đường dẫn `result.json` trong kết quả chạy.

Luồng thử đi qua đăng nhập, chạy thử, nộp bài, API trạng thái và tiến trình Python thật; kiểm tra bài sai định dạng `WA`, bài đúng `AC`, bảo vệ test kín, đầu ra Unicode/giống HTML, cảnh báo cắt ngắn, lịch sử nguồn và bố cục desktop/mobile. Kết nối ngoài bị chặn: **chỉ API của editor Monaco được thay bằng ô nhập thử nghiệm**, không giả lập kết quả API hoặc máy chấm. Vì vậy lượt thử này không xác nhận việc tải Monaco từ CDN.

Hai kiểm tra bổ sung không tạo database:

```powershell
# Kiểm tra cơ chế chờ lưu nháp bằng sự kiện tổng hợp, không mở trình duyệt
node backend/tests/browser_workspace_output_smoke.mjs --test-save-wait

# Kiểm tra CSS/renderer bằng Chrome offline, gồm cửa sổ thấp và mobile
node backend/tests/workspace_short_layout_smoke.mjs
```

Lượt kiểm tra CSS dùng khung trang dựng từ template với dữ liệu tổng hợp, không chạy API, máy chấm hoặc editor Monaco; ảnh chụp và kết quả được giữ trong thư mục tạm riêng.
