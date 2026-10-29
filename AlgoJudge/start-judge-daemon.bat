@echo off
REM start-judge-daemon.bat — Chạy Judge Worker Daemon thường trực cho AlgoJudge
REM Mở file này (chuột phải -> Run), GIỮ cửa sổ mở trong suốt buổi chấm.
REM Daemon tự ghi heartbeat mỗi vòng; khi cửa sổ này mở thì nút Chạy thử /
REM Nộp bài phản hồi tức thì. Nếu quên mở, API vẫn tự chấm fallback.
cd /d C:\xampp\htdocs\AlgoJudge
C:\xampp\php\php.exe backend\worker\judge_daemon.php
