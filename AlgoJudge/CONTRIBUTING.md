# CONTRIBUTING — Quy trình cộng tác nhóm AlgoJudge

## 1. Phân công (mỗi thành viên giải thích được phần đã làm)

| Thành viên | Phần đảm nhận |
|------------|---------------|
| Thành viên 1 | Backend chấm bài: `JudgeEngine.php` (điều phối, so sánh output, tổng hợp), `judge_daemon.php` |
| Thành viên 2 | Backend API + phân quyền: `Auth.php`, `bootstrap.php`, 6 API, kiểm tra đầu vào/đường dẫn |
| Thành viên 3 | Frontend IDE: `workspace.php`, Monaco Editor 6 ngôn ngữ, console chạy thử/nộp bài |
| Thành viên 4 | Frontend quản trị: danh sách bài, lịch sử nộp, trang instructor (dataset/rejudge), trang admin |
| Thành viên 5 | Dữ liệu & kiểm thử: `schema.sql`, seed 60 bài, `run_mandatory_tests.php`, tài liệu bàn giao |

## 2. Quy trình Issue → Review → Merge

1. Mọi việc bắt đầu từ **Issue**: mô tả rõ yêu cầu, tiêu chí nghiệm thu, nhãn (`backend`, `frontend`, `database`, `tests`, `docs`).
2. Mỗi Issue tương ứng **1 nhánh**: `ten-ngan-gon/mo-ta` (VD: `son/judge-js-support`).
3. Mở **Pull Request** về nhánh chính, mô tả: làm gì, test gì, ảnh hưởng gì.
   Cuối mô tả PR thêm dòng: `🤖 Generated with [Claude Code](https://claude.com/claude-code)`
4. Ít nhất **1 thành viên khác review** (check: quyền, đầu vào, test kín không lộ, không mật khẩu/dữ liệu thật).
5. Chỉ merge khi CI/tests pass và review approved.

## 3. Quy tắc code

- PHP thuần, không framework; BE (`backend/`) không chứa HTML, FE (`frontend/`) không chứa SQL chấm/điều phối.
- Mọi dữ liệu in ra trình duyệt phải qua `e()`.
- API test kín: không trả `stdin_data`/`expected_output`/`actual_output` khi `is_public = 0`.
- Chỉ dùng dữ liệu giả; không commit mật khẩu, token hay dữ liệu thật.
- Commit message tiếng Việt hoặc Anh, ngắn gọn, kết thúc bằng:
  `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`
