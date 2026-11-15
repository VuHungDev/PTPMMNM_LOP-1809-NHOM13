<?php
// backend/lib/JudgeQueue.php — Nhóm tự viết: điều phối hàng đợi + fallback khi daemon chết.
// Nguyên tắc: daemon thường trực là đường chính. Nếu daemon >60s không heartbeat
// (chết, chưa khởi động, hoặc lệnh đánh thức nền thất bại trên Windows),
// API tự xử lý ngay trong request để người học không bị kẹt QUEUED mãi.
// Cả daemon và fallback bắt buộc dùng cùng DockerSandbox (kể cả biên dịch).
// Cả biên dịch và chạy mã đều trong Linux container; không thực thi code trên host.

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
        // Under mod_php PHP_BINARY can be httpd.exe; PHP_BIN resolves php.exe.
        // Only this trusted coordinator is spawned on the host, never source text.
        $phpBin = defined('PHP_BIN') ? PHP_BIN : PHP_BINARY;
        $cliCmd = (PHP_OS_FAMILY === 'Windows')
            ? 'start /B "" ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($daemon) . ' --once > NUL 2>&1'
            : escapeshellarg($phpBin) . ' ' . escapeshellarg($daemon) . ' --once > /dev/null 2>&1 &';
        @pclose(@popen($cliCmd, 'r'));
    }

    // Một đường xử lý duy nhất cho daemon và fallback; cùng giới hạn 10s.
    public static function processCustomRun(PDO $pdo, int $customId): void
    {
        $stmt = $pdo->prepare("SELECT * FROM custom_runs WHERE id = ? AND status = 'QUEUED' LIMIT 1");
        $stmt->execute([$customId]);
        $custom = $stmt->fetch();
        if (!$custom) return;
        $claim = $pdo->prepare("UPDATE custom_runs SET status = 'RUNNING' WHERE id = ? AND status = 'QUEUED'");
        $claim->execute([$customId]);
        if ($claim->rowCount() !== 1) return;

        $program = null;
        $exec = ['verdict' => 'SYS_ERR', 'stdout' => '', 'stderr' => '', 'time_ms' => 0];
        try {
            $comp = JudgeEngine::prepareProgram($custom['language'], $custom['source_code'], DockerSandbox::CUSTOM_MEMORY_KB);
            if (!$comp['ok']) {
                $exec['verdict'] = $comp['type'];
                $exec['stderr'] = $comp['message'];
            } else {
                $program = $comp['program'];
                $exec = JudgeEngine::runSingleTestCase($program, $custom['custom_stdin'], DockerSandbox::CUSTOM_TIME_LIMIT_MS);
            }
        } catch (Throwable $e) {
            $exec['verdict'] = 'SYS_ERR';
            $exec['stderr'] = 'Docker sandbox unavailable (no host fallback).';
        } finally {
            if ($program !== null && !JudgeEngine::releaseProgram($program)) {
                $exec['verdict'] = 'SYS_ERR';
                $exec['stderr'] = 'Docker sandbox cleanup failed; operator cleanup is required.';
            }
        }
        $exec['stdout'] = DockerSandbox::storageText($exec['stdout'], 8000);
        $exec['stderr'] = DockerSandbox::storageText($exec['stderr'], 8000);
        $parsed = in_array($exec['verdict'], ['CE', 'RE'], true)
            ? JudgeEngine::parseRuntimeError($custom['language'], $exec['stderr'])
            : ['line' => null, 'message' => '', 'hint' => ''];
        // Parsed Python/PHP messages (and Python module names inside hints) can
        // exceed VARCHAR(500). Cap bytes at UTF-8 boundaries after sanitization;
        // this is also safely below the column's 500-character limit.
        $parsed['message'] = DockerSandbox::storageText($parsed['message'], 500);
        $parsed['hint'] = DockerSandbox::storageText($parsed['hint'], 500);
        $parsed['line'] = $parsed['line'] !== null && $parsed['line'] > 0 && $parsed['line'] <= 2147483647
            ? (int)$parsed['line'] : null;
        try {
            $pdo->prepare("UPDATE custom_runs SET status = 'DONE', verdict = ?, stdout_output = ?, stderr_output = ?, execution_time_ms = ?, error_line = ?, error_message = ?, error_hint = ? WHERE id = ? AND status = 'RUNNING'")
                ->execute([$exec['verdict'], mb_substr($exec['stdout'], 0, 2000), mb_substr($exec['stderr'], 0, 2000), $exec['time_ms'], $parsed['line'], $parsed['message'], $parsed['hint'], $customId]);
        } catch (Throwable $e) {
            // Keep the worker alive and terminate this run even if a diagnostic
            // cannot be saved. Never interpolate contestant diagnostics into SQL.
            try {
                $pdo->prepare("UPDATE custom_runs SET status = 'DONE', verdict = 'SYS_ERR', error_line = NULL, error_message = ?, error_hint = '' WHERE id = ? AND status = 'RUNNING'")
                    ->execute(['Không thể lưu kết quả chạy. Vui lòng thử lại.', $customId]);
            } catch (Throwable $persistError) {
                // A disconnected DB cannot be repaired here; retain the run for
                // operator recovery rather than terminating the whole daemon.
                error_log('Judge custom-run persistence unavailable; operator recovery required.');
            }
        }
    }

    // Fallback chỉ đổi tiến trình điều phối; vẫn Docker, biên dịch <=30s + chạy <=10s.
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
