<?php
// backend/lib/JudgeEngine.php — NHÓM TỰ VIẾT: điều phối chấm, so sánh đầu ra,
// tổng hợp kết quả. Không dùng thư viện chấm bài có sẵn.
// Hỗ trợ 6 ngôn ngữ: C, C++, Java, Python, PHP, JS (chương trình 1 tệp, stdin/stdout).

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/OutputComparator.php';
require_once __DIR__ . '/DockerSandbox.php';

final class JudgeEngine
{
    public const LANGUAGES = [
        'c'      => ['label' => 'C (gcc)',        'ext' => 'c',    'monaco' => 'c'],
        'cpp'    => ['label' => 'C++17 (g++)',    'ext' => 'cpp',  'monaco' => 'cpp'],
        'java'   => ['label' => 'Java (javac)',   'ext' => 'java', 'monaco' => 'java'],
        'python' => ['label' => 'Python 3',       'ext' => 'py',   'monaco' => 'python'],
        'php'    => ['label' => 'PHP',            'ext' => 'php',  'monaco' => 'php'],
        'js'     => ['label' => 'JavaScript (Node)', 'ext' => 'js', 'monaco' => 'javascript'],
    ];

    public static function isSupported(string $lang): bool
    {
        return isset(self::LANGUAGES[$lang]);
    }

    public static function normalizeOutput(string $out): string
    {
        return OutputComparator::normalize($out);
    }

    public static function prepareProgram(string $lang, string $sourceCode, int $memoryLimitKb = DockerSandbox::DEFAULT_MEMORY_KB): array
    {
        if (defined('JUDGE_SANDBOX_SIMULATE_DOWN') && JUDGE_SANDBOX_SIMULATE_DOWN === true) {
            return ['ok' => false, 'type' => 'SYS_ERR', 'message' => 'Docker sandbox service unreachable (no host fallback).'];
        }
        return DockerSandbox::prepare($lang, $sourceCode, $memoryLimitKb);
    }

    // Compatibility entry point: caller workDir is ignored. Source and artifacts
    // live only in the helper-owned isolated job. exec_cmd is an opaque ARRAY handle.
    public static function compileOrCheckSyntax(string $lang, string $sourceCode, string $workDir = '', int $memoryLimitKb = DockerSandbox::DEFAULT_MEMORY_KB): array
    {
        $result = self::prepareProgram($lang, $sourceCode, $memoryLimitKb);
        if ($result['ok']) $result['exec_cmd'] = $result['program'];
        return $result;
    }

    public static function runSingleTestCase($program, string $stdinData, int $timeLimitMs): array
    {
        if (defined('JUDGE_SANDBOX_SIMULATE_DOWN') && JUDGE_SANDBOX_SIMULATE_DOWN === true) {
            return ['verdict' => 'SYS_ERR', 'time_ms' => 0, 'exit_code' => -1, 'stdout' => '', 'stderr' => 'Docker sandbox disconnected (no host fallback).'];
        }
        return DockerSandbox::run($program, $stdinData, $timeLimitMs);
    }

    public static function releaseProgram($program): bool
    {
        return DockerSandbox::release($program);
    }

    // Phân tích stderr thành: dòng lỗi trong mã nguồn + thông điệp gọn + gợi ý tiếng Việt.
    // Dùng cho Sân chơi tự do: chỉ rõ "dòng nào sai" thay vì đổ cả stack trace.
    public static function parseRuntimeError(string $lang, string $stderr): array
    {
        $line = null;
        $message = '';
        $hint = '';
        $stderr = trim($stderr);
        $lastLine = '';
        foreach (explode("\n", $stderr) as $l) {
            $l = trim($l);
            if ($l !== '') {
                $lastLine = $l;
            }
        }

        switch ($lang) {
            case 'python':
                // SyntaxError in ra "File ..., line N" (chữ thường); runtime in ra 'File "...", line N'.
                if (preg_match('/File "[^"]*solution\.py", line (\d+)/', $stderr, $m)) {
                    $line = (int)$m[1];
                } elseif (preg_match('/line (\d+)/', $lastLine, $m)) {
                    $line = (int)$m[1];
                }
                $message = $lastLine;
                if (stripos($stderr, 'ModuleNotFoundError') !== false) {
                    preg_match("/No module named '([^']+)'/", $stderr, $mm);
                    $hint = 'Thiếu thư viện Python' . (isset($mm[1]) ? " '" . $mm[1] . "'" : '') . '. Chỉ các gói được quản trị viên đóng sẵn trong image Docker mới sử dụng được; không dùng thư viện trên máy chủ, cài gói hoặc truy cập mạng khi chạy.';
                } elseif (stripos($stderr, 'EOFError') !== false) {
                    $hint = 'Chương trình gọi input() nhưng ô "Dữ liệu nhập" đang trống. Hãy nhập dữ liệu vào ô rồi bấm Chạy lại.';
                } elseif (stripos($stderr, 'SyntaxError') !== false || stripos($stderr, 'IndentationError') !== false) {
                    $hint = 'Lỗi cú pháp: kiểm tra ngoặc, dấu hai chấm và thụt đầu dòng ở dòng báo lỗi.';
                } elseif (stripos($stderr, 'NameError') !== false) {
                    $hint = 'Dùng tên biến/hàm chưa khai báo: kiểm tra chính tả và thứ tự khai báo.';
                } elseif (stripos($stderr, 'IndexError') !== false) {
                    $hint = 'Truy cập phần tử ngoài phạm vi: với input(), hãy tách chuỗi bằng .split() và kiểm tra số lượng trước khi dùng.';
                } elseif (stripos($stderr, 'ValueError') !== false) {
                    $hint = 'Ép kiểu thất bại (vd int("abc")): kiểm tra dữ liệu nhập có đúng định dạng số không.';
                } elseif (stripos($stderr, 'TypeError') !== false) {
                    $hint = 'Sai kiểu dữ liệu trong phép toán: kiểm tra kiểu của từng biến ở dòng báo lỗi.';
                }
                break;
            case 'js':
                if (preg_match('/solution\.js:(\d+)/', $stderr, $m)) {
                    $line = (int)$m[1];
                }
                // Stack Node.js: dòng lỗi thật có dạng "ReferenceError: ..." nằm giữa,
                // dòng cuối chỉ là frame nội bộ (at Module._compile...) — phải tìm riêng.
                $message = $lastLine;
                foreach (explode("\n", $stderr) as $l) {
                    if (preg_match('/^\w*Error\s*:.+/', trim($l))) {
                        $message = mb_substr(trim($l), 0, 300);
                        break;
                    }
                }
                if (stripos($stderr, 'Cannot find module') !== false) {
                    $hint = 'Thiếu module Node: hỗ trợ module chuẩn và các gói npm đã được đóng sẵn trong ảnh Docker tại /opt/judge/node_modules. Không dùng thư viện trên máy chủ hoặc cài gói khi chạy.';
                } elseif (stripos($stderr, 'SyntaxError') !== false) {
                    $hint = 'Lỗi cú pháp JavaScript: kiểm tra ngoặc, dấu chấm phẩy và từ khóa ở dòng báo lỗi.';
                } elseif (stripos($stderr, 'ReferenceError') !== false) {
                    $hint = 'Dùng biến chưa khai báo: kiểm tra chính tả tên biến.';
                } elseif (stripos($stderr, 'TypeError') !== false) {
                    $hint = 'Sai kiểu dữ liệu: kiểm tra giá trị undefined/null ở dòng báo lỗi.';
                }
                break;
            case 'php':
                if (preg_match('/on line (\d+)/', $stderr, $m)) {
                    $line = (int)$m[1];
                }
                $message = $lastLine;
                if (stripos($stderr, 'Parse error') !== false) {
                    $hint = 'Lỗi cú pháp PHP: kiểm tra dấu chấm phẩy, ngoặc và thẻ mở <?php ở dòng báo lỗi.';
                } elseif (stripos($stderr, 'Call to undefined function') !== false) {
                    $hint = 'Gọi hàm chưa có: kiểm tra chính tả tên hàm hoặc extension PHP còn thiếu.';
                }
                break;
            case 'java':
                if (preg_match('/Main\.java:(\d+)/', $stderr, $m)) {
                    $line = (int)$m[1];
                }
                $message = mb_substr($lastLine, 0, 300);
                // javac in dòng "N error(s)" ở cuối — lấy dòng "error:" thật.
                foreach (explode("\n", $stderr) as $l) {
                    if (stripos($l, 'error:') !== false) {
                        $message = mb_substr(trim($l), 0, 300);
                        break;
                    }
                }
                if (stripos($stderr, 'should be declared in a file') !== false || stripos($stderr, 'is public, should be') !== false) {
                    $hint = 'Sân chơi yêu cầu lớp chính đặt tên là "public class Main". Hãy đổi tên lớp thành Main.';
                } elseif (stripos($stderr, 'cannot find symbol') !== false) {
                    $hint = 'Dùng tên chưa khai báo: kiểm tra chính tả biến/hàm và import (vd import java.util.*).';
                } elseif (stripos($stderr, 'package .* does not exist') !== false) {
                    $hint = 'Thiếu thư viện Java ngoài JDK: Sân chơi chỉ hỗ trợ thư viện chuẩn JDK. Thư viện ngoài cần liên hệ quản trị viên.';
                }
                break;
            case 'c':
            case 'cpp':
                if (preg_match('/solution\.(?:c|cpp):(\d+)/', $stderr, $m)) {
                    $line = (int)$m[1];
                }
                $message = mb_substr($lastLine, 0, 300);
                if (stripos($stderr, 'No such file or directory') !== false && stripos($stderr, '#include') !== false) {
                    $hint = 'Thiếu file header: Sân chơi hỗ trợ thư viện chuẩn C/C++. Thư viện ngoài cần liên hệ quản trị viên.';
                } elseif (stripos($stderr, 'was not declared') !== false || stripos($stderr, 'undeclared') !== false) {
                    $hint = 'Dùng tên chưa khai báo: kiểm tra #include và chính tả ở dòng báo lỗi.';
                }
                break;
        }

        if ($message === '') {
            $message = mb_substr($lastLine !== '' ? $lastLine : $stderr, 0, 300);
        }
        return ['line' => $line, 'message' => $message, 'hint' => $hint];
    }

    public static function processJudgingRun(PDO $pdo, int $runId, bool $simulateDown = false): array
    {
        // Do not join an outer REPEATABLE READ snapshot or hold its locks while
        // the Docker-isolated program runs. It would invalidate the first-solve serialization below.
        if ($pdo->inTransaction()) throw new LogicException('Judging must run outside a caller transaction.');
        $stmt = $pdo->prepare("
            SELECT jr.*, s.user_id, s.language, s.source_code, pv.time_limit_ms, pv.memory_limit_kb
            FROM judging_runs jr
            JOIN submissions s ON s.id = jr.submission_id
            JOIN problem_versions pv ON pv.id = jr.problem_version_id
            WHERE jr.id = ?
        ");
        $stmt->execute([$runId]);
        $run = $stmt->fetch();
        if (!$run) return ['error' => 'Run not found', 'verdict' => 'SYS_ERR'];

        // Short parent-before-run transactions are shared by claim, case writes,
        // finalization and retry. Never hold DB locks during sandbox execution.
        $pdo->beginTransaction();
        try {
            if (self::lockActiveRun($pdo, (int)$run['submission_id'], $runId, 'QUEUED') === null) {
                $pdo->commit();
                return self::skippedRun($pdo, $runId);
            }
            $claim = $pdo->prepare("UPDATE judging_runs SET status = 'RUNNING', started_at = NOW() WHERE id = ? AND status = 'QUEUED'");
            $claim->execute([$runId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $program = null;
        $passCount = 0;
        $totalScore = 0;
        $totalCases = 0;
        $maxTimeMs = 0;
        $overallVerdict = 'SYS_ERR';
        $compilerOutput = null;
        $systemError = null;
        try {
            if ($simulateDown || (defined('JUDGE_SANDBOX_SIMULATE_DOWN') && JUDGE_SANDBOX_SIMULATE_DOWN)) {
                throw new RuntimeException('Docker sandbox service unreachable (no host fallback).');
            }
            $compileRes = self::prepareProgram($run['language'], $run['source_code'], (int)$run['memory_limit_kb']);
            if (!$compileRes['ok']) {
                if ($compileRes['type'] === 'CE') {
                    $overallVerdict = 'CE';
                    $compilerOutput = $compileRes['message'];
                } else {
                    $systemError = $compileRes['message'];
                }
            } else {
                $program = $compileRes['program'];
                $stmtCases = $pdo->prepare("SELECT * FROM test_cases WHERE problem_version_id = ? ORDER BY case_order ASC");
                $stmtCases->execute([$run['problem_version_id']]);
                $cases = $stmtCases->fetchAll();
                $totalCases = count($cases);
                if ($totalCases === 0) throw new RuntimeException('Problem version has no test cases.');
                $overallVerdict = 'AC';
                foreach ($cases as $tc) {
                    // expected_output stays in the trusted judge, never in the isolated job.
                    $exec = self::runSingleTestCase($program, $tc['stdin_data'], (int)$run['time_limit_ms']);
                    $maxTimeMs = max($maxTimeMs, $exec['time_ms']);
                    $caseVerdict = $exec['verdict'];
                    if ($caseVerdict === 'OK') {
                        $caseVerdict = OutputComparator::matches($exec['stdout'], $tc['expected_output']) ? 'AC' : 'WA';
                        if ($caseVerdict === 'AC') { $passCount++; $totalScore += (int)$tc['points']; }
                    }
                    if ($caseVerdict !== 'AC' && $overallVerdict === 'AC') $overallVerdict = $caseVerdict;
                    // Infrastructure failure takes priority even after an earlier WA/RE.
                    if ($caseVerdict === 'SYS_ERR') {
                        $overallVerdict = 'SYS_ERR';
                        $systemError = $exec['stderr'];
                    }
                    if (!self::persistActiveCase($pdo, (int)$run['submission_id'], $runId, [
                        $runId, $tc['id'], $caseVerdict, $exec['time_ms'], $exec['exit_code'],
                        DockerSandbox::storageText($exec['stdout']), DockerSandbox::storageText($exec['stderr']),
                    ])) break;
                    if ($caseVerdict === 'SYS_ERR') break;
                }
            }
        } catch (Throwable $e) {
            $overallVerdict = 'SYS_ERR';
            $systemError = DockerSandbox::storageText($e->getMessage());
        } finally {
            if ($program !== null && !self::releaseProgram($program)) {
                $overallVerdict = 'SYS_ERR';
                $systemError = 'Docker sandbox cleanup failed; operator cleanup is required.';
            }
        }
        if ($overallVerdict === 'SYS_ERR') $totalScore = 0;
        $runStatus = $overallVerdict === 'SYS_ERR' ? 'SYS_ERROR' : 'COMPLETED';
        $pdo->beginTransaction();
        try {
            // XP for different submissions of one student must serialize BEFORE
            // taking any submission/run locks (same user-first order as the APIs).
            $owner = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $owner->execute([(int)$run['user_id']]);
            if (!$owner->fetchColumn() || self::lockActiveRun($pdo, (int)$run['submission_id'], $runId) === null) {
                $pdo->commit();
                return self::skippedRun($pdo, $runId);
            }
            // Check history before replacing the submission's old verdict; this
            // also remembers legacy AC submissions that have no judging-run row.
            if ($overallVerdict === 'AC') self::awardActiveFirstSolveXp($pdo, (int)$run['submission_id'], $runId);
            $finish = $pdo->prepare("
                UPDATE judging_runs
                SET status = ?, verdict = ?, score = ?, pass_count = ?, total_cases = ?, max_time_ms = ?,
                    compiler_output = ?, system_error_detail = ?, finished_at = NOW()
                WHERE id = ? AND status = 'RUNNING'
            ");
            $finish->execute([$runStatus, $overallVerdict, $totalScore, $passCount, $totalCases, $maxTimeMs, $compilerOutput, $systemError, $runId]);
            if ($finish->rowCount() !== 1) {
                $pdo->rollBack();
                return self::skippedRun($pdo, $runId);
            }
            self::syncLatestRunToSubmission($pdo, (int)$run['submission_id'], $runId, $overallVerdict, $totalScore, $passCount, $totalCases);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['verdict' => $overallVerdict, 'score' => $totalScore, 'pass_count' => $passCount, 'total_cases' => $totalCases];
    }

    /** Caller owns a short transaction; always lock the parent before its run. */
    private static function lockActiveRun(PDO $pdo, int $submissionId, int $runId, string $status = 'RUNNING'): ?array
    {
        if (!$pdo->inTransaction()) throw new LogicException('Active-run fence requires a transaction.');
        $parent = $pdo->prepare('SELECT latest_run_id FROM submissions WHERE id = ? FOR UPDATE');
        $parent->execute([$submissionId]);
        $sub = $parent->fetch();
        if (!$sub || (int)$sub['latest_run_id'] !== $runId) return null;
        $stmt = $pdo->prepare('SELECT * FROM judging_runs WHERE id = ? AND submission_id = ? FOR UPDATE');
        $stmt->execute([$runId, $submissionId]);
        $run = $stmt->fetch();
        return $run && $run['status'] === $status ? $run : null;
    }

    private static function persistActiveCase(PDO $pdo, int $submissionId, int $runId, array $values): bool
    {
        $pdo->beginTransaction();
        try {
            if (self::lockActiveRun($pdo, $submissionId, $runId) === null) {
                $pdo->commit();
                return false;
            }
            $pdo->prepare('INSERT INTO case_results (judging_run_id, test_case_id, verdict, execution_time_ms, exit_code, actual_output, runtime_error) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute($values);
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function skippedRun(PDO $pdo, int $runId): array
    {
        $stmt = $pdo->prepare('SELECT verdict, score, pass_count, total_cases FROM judging_runs WHERE id = ?');
        $stmt->execute([$runId]);
        return array_merge($stmt->fetch() ?: ['verdict' => 'SYS_ERR', 'score' => 0], ['skipped' => true]);
    }

    // Kết quả của lần chấm cũ về sau KHÔNG ghi đè kết quả lần mới
    public static function syncLatestRunToSubmission(PDO $pdo, int $submissionId, int $runId, string $verdict, int $score, int $passCount, int $totalCases): bool
    {
        $stmt = $pdo->prepare("
            UPDATE submissions
            SET latest_run_id = :run_id, final_verdict = :verdict, final_score = :score,
                pass_count = :pass_count, total_cases = :total_cases
            WHERE id = :sub_id AND latest_run_id = :run_id_check
                AND EXISTS (SELECT 1 FROM judging_runs jr WHERE jr.id = :finished_run_id
                    AND jr.submission_id = :finished_sub_id AND jr.status IN ('COMPLETED', 'SYS_ERROR')
                    AND jr.verdict = :finished_verdict)
        ");
        $stmt->execute([
            ':run_id' => $runId, ':verdict' => $verdict, ':score' => $score,
            ':pass_count' => $passCount, ':total_cases' => $totalCases,
            ':sub_id' => $submissionId, ':run_id_check' => $runId,
            ':finished_run_id' => $runId, ':finished_sub_id' => $submissionId, ':finished_verdict' => $verdict,
        ]);
        return $stmt->rowCount() > 0;
    }

    // Kept for old callers, but reading/backfilling a historical result must not
    // mint an untracked reward. Only the private atomic finalization path can.
    public static function awardFirstSolveXp(PDO $pdo, int $submissionId): int
    {
        return 0;
    }

    private static function awardActiveFirstSolveXp(PDO $pdo, int $submissionId, int $activeRunId): int
    {
        // Caller holds user -> submission -> RUNNING run locks and rolls this
        // reward back if the conditional accepted final update does not succeed.
        if (self::lockActiveRun($pdo, $submissionId, $activeRunId) === null) return 0;
        $stmt = $pdo->prepare("
            SELECT s.user_id, s.problem_id, p.difficulty
            FROM submissions s JOIN problems p ON p.id = s.problem_id
            WHERE s.id = ?
        ");
        $stmt->execute([$submissionId]);
        $sub = $stmt->fetch();
        if (!$sub) return 0;
        // Finalization's transaction starts with a locking user read, not a
        // snapshot read. Its first consistent read is therefore after the previous
        // reward committed. Do not lock other submissions/runs in this history
        // query: retry holds those rows and may need an FK user lock of its own.
        $chk = $pdo->prepare("
            SELECT s.id FROM submissions s
            WHERE s.user_id = ? AND s.problem_id = ?
                AND (s.final_verdict = 'AC' OR EXISTS (
                    SELECT 1 FROM judging_runs jr WHERE jr.submission_id = s.id
                        AND jr.status = 'COMPLETED' AND jr.verdict = 'AC'
                ))
            LIMIT 1
        ");
        $chk->execute([$sub['user_id'], $sub['problem_id']]);
        if ($chk->fetchColumn() !== false) return 0;
        $xp = ['Easy' => 10, 'Medium' => 20, 'Hard' => 30][$sub['difficulty']] ?? 10;
        $pdo->prepare("UPDATE users SET xp_points = xp_points + ? WHERE id = ?")->execute([$xp, $sub['user_id']]);
        return $xp;
    }
}
