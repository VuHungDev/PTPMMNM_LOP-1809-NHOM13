@echo off
REM Docker judge: compilation and execution stay in hardened Linux containers.
REM Keep this window open while judging. Web fallback uses the same Docker runner.
cd /d "%~dp0"
set "AJ_DOCKER=%LOCALAPPDATA%\Programs\DockerDesktop\resources\bin\docker.exe"
if not exist "%AJ_DOCKER%" set "AJ_DOCKER=%ProgramFiles%\Docker\Docker\resources\bin\docker.exe"
if not exist "%AJ_DOCKER%" set "AJ_DOCKER=docker.exe"
if defined AJ_SANDBOX_DOCKER set "AJ_DOCKER=%AJ_SANDBOX_DOCKER%"
"%AJ_DOCKER%" info --format "{{.OSType}}" >nul 2>&1
if errorlevel 1 (
    echo Starting Docker Desktop. Wait until Engine running in Linux-container mode.
    "%AJ_DOCKER%" desktop start
)
"%AJ_DOCKER%" info --format "{{.OSType}}" | %SystemRoot%\System32\findstr.exe /x "linux" >nul
if errorlevel 1 (
    echo Docker Linux engine is not ready. Open Docker Desktop, then launch this file again.
    pause
    exit /b 1
)
C:\xampp\php\php.exe backend\worker\judge_daemon.php
