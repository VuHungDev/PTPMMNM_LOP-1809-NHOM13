@echo off
REM start-mysql3307.bat — Khởi động MariaDB (XAMPP) trên cổng 3307 cho AlgoJudge
REM Chạy file này 1 lần sau khi mở máy (chuột phải -> Run). Giữ cửa sổ mở.
cd /d C:\xampp\mysql
.\bin\mysqld.exe --datadir=C:/xampp/mysql/data3307 --port=3307
