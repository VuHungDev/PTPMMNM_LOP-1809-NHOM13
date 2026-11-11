@echo off
setlocal EnableExtensions DisableDelayedExpansion
title AlgoJudge - XAMPP MySQL 3307
REM Both this launcher and XAMPP Start use the SAME server configuration.
REM /check is read-only and never requests elevation or starts a process.
set "XAMPP_ROOT=C:\xampp"
set "MYSQL_ROOT=%XAMPP_ROOT%\mysql"
set "DATA_DIR=%MYSQL_ROOT%\data3307"
set "CONFIG_FILE=%MYSQL_ROOT%\bin\my.ini"
set "CHECK_ONLY=0"
if /i "%~1"=="/check" set "CHECK_ONLY=1"
if not exist "%MYSQL_ROOT%\bin\mysqld.exe" goto missing_files
if not exist "%MYSQL_ROOT%\bin\mysql.exe" goto missing_files
if not exist "%MYSQL_ROOT%\bin\mysqladmin.exe" goto missing_files
if not exist "%CONFIG_FILE%" goto missing_files
if not exist "%XAMPP_ROOT%\xampp-control.exe" goto missing_files
if not exist "%DATA_DIR%\ibdata1" goto missing_data
if not exist "%DATA_DIR%\algojudge\" goto missing_data
if "%CHECK_ONLY%"=="1" goto check_server

REM Matching administrator rights let XAMPP detect and control this process.
powershell.exe -NoProfile -NonInteractive -Command "$p = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent()); if ($p.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { exit 0 }; exit 1"
if not errorlevel 1 goto check_server
echo Can quyen Administrator de XAMPP quan ly MySQL. Hay bam Yes trong UAC.
set "AJ_LAUNCHER=%~f0"
powershell.exe -NoProfile -NonInteractive -Command "try { $args = '/d /c ' + [char]34 + [char]34 + $env:AJ_LAUNCHER + [char]34 + [char]34; Start-Process -FilePath $env:ComSpec -ArgumentList $args -Verb RunAs -ErrorAction Stop; exit 0 } catch { Write-Host 'UAC bi huy hoac khong mo duoc launcher.'; exit 1 }"
if errorlevel 1 goto failed
exit /b 0

:check_server
echo.
echo === AlgoJudge - XAMPP MySQL 3307 ===
echo Data: %DATA_DIR%
echo Config: %CONFIG_FILE%
"%MYSQL_ROOT%\bin\mysqladmin.exe" --no-defaults --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --connect-timeout=3 ping >nul 2>&1
if not errorlevel 1 goto verify_server
powershell.exe -NoProfile -NonInteractive -Command "try { $listeners = [System.Net.NetworkInformation.IPGlobalProperties]::GetIPGlobalProperties().GetActiveTcpListeners(); foreach ($l in $listeners) { if ($l.Port -eq 3307) { exit 10 } }; exit 0 } catch { exit 20 }"
if errorlevel 1 goto port_conflict
if "%CHECK_ONLY%"=="1" goto not_running

REM Refuse to start when the shared XAMPP config has drifted to another datadir.
powershell.exe -NoProfile -NonInteractive -Command "$v = & ($env:MYSQL_ROOT + '\bin\mysqld.exe') ('--defaults-file=' + $env:CONFIG_FILE) --print-defaults; if ($LASTEXITCODE -ne 0 -or ($v -join ' ') -notmatch '(?i)--port=3307(?:\s|$)' -or ($v -join ' ') -notmatch '(?i)--datadir=C:/xampp/mysql/data3307(?:\s|$)') { Write-Host 'Config XAMPP khong khop 3307/data3307. Khong khoi dong.'; exit 1 }"
if errorlevel 1 goto failed
echo Dang khoi dong MySQL bang cau hinh XAMPP...
powershell.exe -NoProfile -NonInteractive -Command "try { $args = '--defaults-file=' + [char]34 + $env:CONFIG_FILE + [char]34 + ' --standalone'; $p = Start-Process -FilePath ($env:MYSQL_ROOT + '\bin\mysqld.exe') -ArgumentList $args -WorkingDirectory $env:XAMPP_ROOT -WindowStyle Hidden -PassThru -ErrorAction Stop; $deadline = [DateTime]::UtcNow.AddSeconds(20); do { if ($p.HasExited) { Write-Host 'MySQL dung som. Xem log trong data3307.'; exit 1 }; & ($env:MYSQL_ROOT + '\bin\mysqladmin.exe') --no-defaults --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --connect-timeout=1 ping *> $null; if ($LASTEXITCODE -eq 0) { exit 0 }; Start-Sleep -Milliseconds 500 } while ([DateTime]::UtcNow -lt $deadline); Write-Host 'Het thoi gian cho. Kiem tra log; KHONG chay them instance.'; exit 1 } catch { Write-Host $_.Exception.Message; exit 1 }"
if errorlevel 1 goto failed

:verify_server
"%MYSQL_ROOT%\bin\mysql.exe" --no-defaults --protocol=TCP --host=127.0.0.1 --port=3307 --user=root --connect-timeout=3 --batch --skip-column-names --execute="SELECT IF(@@port = 3307 AND LOWER(REPLACE(@@datadir, CHAR(92), '/')) = 'c:/xampp/mysql/data3307/', 'ALGOJUDGE_3307_OK', 'OTHER_INSTANCE');" 2>nul | findstr /X /C:"ALGOJUDGE_3307_OK" >nul
if errorlevel 1 goto port_conflict
echo MySQL AlgoJudge da san sang tai 127.0.0.1:3307.
if "%CHECK_ONLY%"=="1" exit /b 0
REM Never kill an existing panel automatically. It may be controlling Apache too.
tasklist /FI "IMAGENAME eq xampp-control.exe" /NH 2>nul | findstr /I /C:"xampp-control.exe" >nul
if not errorlevel 1 goto panel_ready
start "XAMPP Control Panel" /D "%XAMPP_ROOT%" "%XAMPP_ROOT%\xampp-control.exe"
:panel_ready
echo XAMPP va launcher dung chung MySQL 3307. Dung Start/Stop MySQL trong panel.
echo Neu panel cu chua cap nhat, Quit panel va mo lai bang Run as administrator.
echo Co the dong cua so BAT nay; MySQL chay nen va duoc quan ly trong XAMPP.
echo Website: http://localhost/AlgoJudge/
pause
exit /b 0

:not_running
echo MySQL 3307 chua chay. /check khong khoi dong database.
exit /b 0
:port_conflict
echo [LOI] Cong 3307 dang duoc dung hoac chua xac minh duoc dung database.
echo Khong khoi dong them va khong tu dung tien trinh khac.
goto failed
:missing_files
echo [LOI] Thieu XAMPP, MariaDB hoac file cau hinh: %CONFIG_FILE%
goto failed
:missing_data
echo [LOI] Thieu du lieu tai %DATA_DIR%. Khong tao lai hay ghi de database.
goto failed
:failed
if "%CHECK_ONLY%"=="1" exit /b 1
pause
exit /b 1
