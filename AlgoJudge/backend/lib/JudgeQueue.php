<?php
// backend/lib/JudgeQueue.php — Nhóm tự viết: điều phối hàng đợi + fallback khi daemon chết.
// Nguyên tắc: daemon thường trực là đường chính. Nếu daemon >60s không heartbeat
// (chết, chưa khởi động, hoặc lệnh đánh thức nền thất bại trên Windows),
// API tự xử lý ngay trong request để người học không bị kẹt QUEUED mãi.
// Mã nguồn vẫn chạy trong tiến trình OS con riêng qua proc_open (JudgeEngine),
// không eval trong Web — chỉ khác là ai kích hoạt vòng xử lý.

require_once __DIR__ . '/../lib/JudgeEngine.php';

final class JudgeQueue
{
    public const HEARTBEAT_TTL_SECONDS = 60;

    public static function isDaemonAlive(PDO $pdo): bool
    {
        try {
            // So sánh bằng giờ của DB (TIMESTAMPDIFF) để tránh lệch múi giờ
            // giữa PHP và MySQL làm heartbeat cũ bị tính là "mới".
            $age = $pdo->query("SELECT TIMESTAMPDIFF(SECOND, last_beat, NOW()) AS age FROM daemon_heartbeat WHERE name = 'judge_daemon'")->fetch();
            if (!$age || $age['age'] === null) {
                return false;
            }
            return (int)$age['age'] <= self::HEARTBEAT_TTL_SECONDS;
        } catch (Exception $e) {
            return false;
        }
    }

    // Cố đánh thức daemon nền (best-effort, có thể thất bại trên Windows — đã có fallback).
    public static function wakeDaemon(): void
    {
        $daemon = BE_DIR . '/worker/judge_daemon.php';
        $phpBin = PHP_BINARY;
        $cliCmd = (PHP_OS_FAMILY === 'Windows')
            ? 'start /B "" ' . $phpBin . ' ' . escapeshellarg($daemon) . ' --once > NUL 2>&1'
            : $phpBin . ' ' . escapeshellarg($daemon) . ' --once > /dev/null 2>&1 &';
        @pclose(@popen($cliCmd, 'r'));
    }

    // Xử lý 1 custom_run cụ thể (dùng cho fallback chạy thử, giới hạn 10s).
    // 10s (thay vì 2s) để lần import đầu các thư viện nặng (pandas, sklearn...)
    // vẫn kịp chạy; vòng lặp vô hạn thật vẫn bị cắt và báo TLE như thường.
    public static function processCustomRun(PDO $pdo, int $customId): void
    {
        $stmt = $pdo->prepare("SELECT * FROM custom_runs WHERE id = ? AND status = 'QUEUED' LIMIT 1");
        $stmt->execute([$customId]);
        $custom = $stmt->fetch();
        if (!$custom) {
            return;
        }
        $pdo->prepare("UPDATE custom_runs SET status = 'RUNNING' WHERE id = ?")->execute([$customId]);
        $workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cl_custom_' . $customId;
        @mkdir($workDir, 0700, true);

        $comp = JudgeEngine::compileOrCheckSyntax($custom['language'], $custom['source_code'], $workDir);
        if (!$comp['ok']) {
            $parsed = ($comp['type'] === 'CE')
                ? JudgeEngine::parseRuntimeError($custom['language'], $comp['message'])
                : ['line' => null, 'message' => '', 'hint' => ''];
            $pdo->prepare("UPDATE custom_runs SET status = 'DONE', verdict = ?, stderr_output = ?, error_line = ?, error_message = ?, error_hint = ? WHERE id = ?")
                ->execute([$comp['type'], $comp['message'], $parsed['line'], $parsed['message'], $parsed['hint'], $customId]);
        } else {
            $exec = JudgeEngine::runSingleTestCase($comp['exec_cmd'], $custom['custom_stdin'], 10000);
            $parsed = ($exec['verdict'] === 'RE')
                ? JudgeEngine::parseRuntimeError($custom['language'], $exec['stderr'])
                : ['line' => null, 'message' => '', 'hint' => ''];
            $pdo->prepare("UPDATE custom_runs SET status = 'DONE', verdict = ?, stdout_output = ?, stderr_output = ?, execution_time_ms = ?, error_line = ?, error_message = ?, error_hint = ? WHERE id = ?")
                ->execute([$exec['verdict'], mb_substr($exec['stdout'], 0, 2000), mb_substr($exec['stderr'], 0, 2000), $exec['time_ms'], $parsed['line'], $parsed['message'], $parsed['hint'], $customId]);
        }
    }

    // Fallback cho chạy thử: nếu daemon chết thì chấm luôn trong request (nhanh, <= ~5s).
    public static function ensureCustomRunProcessed(PDO $pdo, int $customId): void
    {
        self::wakeDaemon();
        if (!self::isDaemonAlive($pdo)) {
            self::processCustomRun($pdo, $customId);
        }
    }

    // Fallback cho nộp bài: nếu daemon chết thì chấm luôn run vừa tạo.
    // Bài nhiều test có thể mất >30s nên chỉ fallback khi daemon chết hẳn.
    public static function ensureJudgingRunProcessed(PDO $pdo, int $runId): void
    {
        self::wakeDaemon();
        if (!self::isDaemonAlive($pdo)) {
            JudgeEngine::processJudgingRun($pdo, $runId);
        }
    }
}
