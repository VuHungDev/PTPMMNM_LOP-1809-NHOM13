<?php
// backend/lib/JudgeEngine.php — NHÓM TỰ VIẾT: điều phối chấm, so sánh đầu ra,
// tổng hợp kết quả. Không dùng thư viện chấm bài có sẵn.
// Hỗ trợ 6 ngôn ngữ: C, C++, Java, Python, PHP, JS (chương trình 1 tệp, stdin/stdout).

require_once __DIR__ . '/../config/bootstrap.php';

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
        $out = str_replace("\r\n", "\n", trim($out));
        return implode("\n", array_map('rtrim', explode("\n", $out)));
    }

    private static function bin(string $exe): string
    {
        return $exe . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    }

    // Kiểm tra công cụ biên dịch/thông dịch có tồn tại không, TRƯỚC khi đổ lỗi cho code.
    // Thiếu toolchain là lỗi hệ thống (SYS_ERR, được thử lại), không phải lỗi của học viên.
    private static function toolAvailable(string $exe): bool
    {
        // Đường dẫn tuyệt đối (vd PHP_BIN khi chạy qua Apache): kiểm tra file trực tiếp.
        if (strpbrk($exe, '/\\') !== false) {
            return is_file($exe) && is_executable($exe);
        }
        $probe = PHP_OS_FAMILY === 'Windows'
            ? 'where ' . escapeshellarg($exe) . ' 2>NUL'
            : 'command -v ' . escapeshellarg($exe) . ' >/dev/null 2>&1';
        exec($probe, $out, $code);
        return $code === 0;
    }

    private static function missingToolchain(string $lang): array
    {
        $hint = [
            'c'   => 'Máy chấm chưa cài trình biên dịch C (gcc). Hãy cài MSYS2 (gói mingw-w64-ucrt-x86_64-gcc) rồi khởi động lại Apache.',
            'cpp' => 'Máy chấm chưa cài trình biên dịch C++ (g++). Hãy cài MSYS2 (gói mingw-w64-ucrt-x86_64-gcc) rồi khởi động lại Apache.',
        ][$lang] ?? ('Máy chấm thiếu công cụ thực thi ngôn ngữ ' . $lang . '. Hãy liên hệ quản trị viên.');
        return ['ok' => false, 'type' => 'SYS_ERR', 'message' => $hint];
    }

    // Ghép biến môi trường vào đầu lệnh cho đúng cú pháp shell từng HĐH.
    // Windows (cmd.exe): set "K=V" && <cmd> — cú pháp sh (K=V cmd) không chạy được.
    private static function withEnv(array $env, string $cmd): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $prefix = '';
            foreach ($env as $k => $v) {
                $prefix .= 'set "' . $k . '=' . $v . '" && ';
            }
            return $prefix . $cmd;
        }
        $prefix = '';
        foreach ($env as $k => $v) {
            $prefix .= $k . '=' . escapeshellarg($v) . ' ';
        }
        return $prefix . $cmd;
    }

    // Toolchain MSYS2 cần thư mục bin của nó trong PATH để cc1.exe load được
    // các DLL phụ thuộc (libisl, libmpfr...). PATH của Apache service thường
    // không có thư mục này nên phải bổ sung mỗi lần gọi biên dịch.
    private static function toolchainEnv(string $exe): array
    {
        if (PHP_OS_FAMILY === 'Windows' && strpbrk($exe, '/\\') !== false) {
            $dir = str_replace('/', DIRECTORY_SEPARATOR, dirname($exe));
            return ['PATH' => $dir . ';' . getenv('PATH')];
        }
        return [];
    }

    public static function compileOrCheckSyntax(string $lang, string $sourceCode, string $workDir): array
    {
        if (defined('JUDGE_SANDBOX_SIMULATE_DOWN') && JUDGE_SANDBOX_SIMULATE_DOWN === true) {
            return ['ok' => false, 'type' => 'SYS_ERR', 'message' => 'Sandbox Runner Service Unreachable'];
        }

        $isWin = PHP_OS_FAMILY === 'Windows';

        // Công cụ nào dùng cho từng ngôn ngữ — kiểm tra sự tồn tại TRƯỚC khi biên dịch.
        $needTool = [
            'python' => PYTHON_BIN,
            'cpp'    => self::bin(GPP_BIN),
            'c'      => self::bin(GCC_BIN),
            'java'   => self::bin(JAVAC_BIN),
            'php'    => PHP_BIN,
            'js'     => NODE_BIN,
        ];
        if (isset($needTool[$lang]) && !self::toolAvailable($needTool[$lang])) {
            return self::missingToolchain($lang);
        }

        switch ($lang) {
            case 'python': {
                $src = $workDir . DIRECTORY_SEPARATOR . 'solution.py';
                file_put_contents($src, $sourceCode);
                // Ép UTF-8 cho mọi tiến trình Python của máy chấm: Windows mặc định
                // dùng cp1252 cho pipe khiến input()/print() có tiếng Việt bị
                // UnicodeEncodeError và sinh bytes lỗi làm vỡ cột DB (utf8mb4).
                $pyEnv = ['PYTHONIOENCODING' => 'utf-8'];
                exec(self::withEnv($pyEnv, PYTHON_BIN . ' -m py_compile ' . escapeshellarg($src) . ' 2>&1'), $out, $code);
                if ($code !== 0) {
                    return ['ok' => false, 'type' => 'CE', 'message' => implode("\n", $out)];
                }
                return ['ok' => true, 'exec_cmd' => self::withEnv($pyEnv, PYTHON_BIN . ' ' . escapeshellarg($src))];
            }
            case 'cpp': {
                $src = $workDir . DIRECTORY_SEPARATOR . 'solution.cpp';
                $bin = $workDir . DIRECTORY_SEPARATOR . ($isWin ? 'solution.exe' : 'solution.out');
                file_put_contents($src, $sourceCode);
                exec(self::withEnv(self::toolchainEnv(GPP_BIN), self::bin(GPP_BIN) . ' -O2 -std=c++17 ' . escapeshellarg($src) . ' -o ' . escapeshellarg($bin) . ' 2>&1'), $out, $code);
                if ($code !== 0) {
                    return ['ok' => false, 'type' => 'CE', 'message' => implode("\n", $out)];
                }
                return ['ok' => true, 'exec_cmd' => self::withEnv(self::toolchainEnv(GPP_BIN), escapeshellarg($bin))];
            }
            case 'c': {
                $src = $workDir . DIRECTORY_SEPARATOR . 'solution.c';
                $bin = $workDir . DIRECTORY_SEPARATOR . ($isWin ? 'solution_c.exe' : 'solution_c.out');
                file_put_contents($src, $sourceCode);
                exec(self::withEnv(self::toolchainEnv(GCC_BIN), self::bin(GCC_BIN) . ' -O2 -std=c11 ' . escapeshellarg($src) . ' -o ' . escapeshellarg($bin) . ' 2>&1'), $out, $code);
                if ($code !== 0) {
                    return ['ok' => false, 'type' => 'CE', 'message' => implode("\n", $out)];
                }
                return ['ok' => true, 'exec_cmd' => self::withEnv(self::toolchainEnv(GCC_BIN), escapeshellarg($bin))];
            }
            case 'java': {
                $src = $workDir . DIRECTORY_SEPARATOR . 'Main.java';
                file_put_contents($src, $sourceCode);
                exec(escapeshellarg(self::bin(JAVAC_BIN)) . ' -encoding UTF-8 ' . escapeshellarg($src) . ' 2>&1', $out, $code);
                if ($code !== 0) {
                    return ['ok' => false, 'type' => 'CE', 'message' => implode("\n", $out)];
                }
                return ['ok' => true, 'exec_cmd' => escapeshellarg(self::bin(JAVA_BIN)) . ' -cp ' . escapeshellarg($workDir) . ' Main'];
            }
            case 'php': {
                $src = $workDir . DIRECTORY_SEPARATOR . 'solution.php';
                file_put_contents($src, $sourceCode);
                exec(escapeshellarg(PHP_BIN) . ' -l ' . escapeshellarg($src) . ' 2>&1', $out, $code);
                if ($code !== 0) {
                    return ['ok' => false, 'type' => 'CE', 'message' => implode("\n", $out)];
                }
                return ['ok' => true, 'exec_cmd' => escapeshellarg(PHP_BIN) . ' ' . escapeshellarg($src)];
            }
            case 'js': {
                $src = $workDir . DIRECTORY_SEPARATOR . 'solution.js';
                file_put_contents($src, $sourceCode);
                // NODE_PATH trỏ vào sandbox npm của máy chấm để require() được
                // các gói đã cài sẵn (lodash, mathjs, dayjs...); code vẫn chạy
                // trong tiến trình OS con riêng qua proc_open.
                $nodeEnv = ['NODE_PATH' => NODE_SANDBOX_DIR . DIRECTORY_SEPARATOR . 'node_modules'];
                exec(self::withEnv($nodeEnv, NODE_BIN . ' --check ' . escapeshellarg($src) . ' 2>&1'), $out, $code);
                if ($code !== 0) {
                    return ['ok' => false, 'type' => 'CE', 'message' => implode("\n", $out)];
                }
                return ['ok' => true, 'exec_cmd' => self::withEnv($nodeEnv, NODE_BIN . ' ' . escapeshellarg($src))];
            }
        }

        return ['ok' => false, 'type' => 'SYS_ERR', 'message' => 'Unsupported language'];
    }

    public static function runSingleTestCase(string $execCmd, string $stdinData, int $timeLimitMs): array
    {
        if (defined('JUDGE_SANDBOX_SIMULATE_DOWN') && JUDGE_SANDBOX_SIMULATE_DOWN === true) {
            return ['verdict' => 'SYS_ERR', 'time_ms' => 0, 'exit_code' => -1, 'stdout' => '', 'stderr' => 'Runner disconnected'];
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $startTime = microtime(true);
        $process = @proc_open($execCmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            return ['verdict' => 'SYS_ERR', 'time_ms' => 0, 'exit_code' => -1, 'stdout' => '', 'stderr' => 'Cannot spawn isolated process'];
        }

        fwrite($pipes[0], $stdinData);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $elapsedMs = (int)round((microtime(true) - $startTime) * 1000);
            if ($elapsedMs > $timeLimitMs) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }
            if (!$status['running']) {
                break;
            }
            usleep(10000);
        }

        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $elapsedMs = (int)round((microtime(true) - $startTime) * 1000);

        if ($timedOut) {
            return ['verdict' => 'TLE', 'time_ms' => $timeLimitMs, 'exit_code' => $exitCode, 'stdout' => mb_substr($stdout, 0, 1024), 'stderr' => 'Time Limit Exceeded'];
        }
        if ($exitCode !== 0) {
            return ['verdict' => 'RE', 'time_ms' => $elapsedMs, 'exit_code' => $exitCode, 'stdout' => mb_substr($stdout, 0, 1024), 'stderr' => mb_substr($stderr, 0, 1024)];
        }
        return ['verdict' => 'OK', 'time_ms' => $elapsedMs, 'exit_code' => 0, 'stdout' => $stdout, 'stderr' => $stderr];
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
                    $hint = 'Thiếu thư viện Python' . (isset($mm[1]) ? " '" . $mm[1] . "'" : '') . '. Máy chấm đã có sẵn numpy, pandas, requests, scikit-learn, matplotlib, scipy, sympy. Tên thư viện khác cần liên hệ quản trị viên để cài thêm.';
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
                    $hint = 'Thiếu module Node: Sân chơi chỉ hỗ trợ các module có sẵn (fs, path, readline, util, crypto, os...). Module ngoài (npm) cần liên hệ quản trị viên.';
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
        $stmt = $pdo->prepare("
            SELECT jr.*, s.language, s.source_code, pv.time_limit_ms
            FROM judging_runs jr
            JOIN submissions s ON s.id = jr.submission_id
            JOIN problem_versions pv ON pv.id = jr.problem_version_id
            WHERE jr.id = ?
        ");
        $stmt->execute([$runId]);
        $run = $stmt->fetch();
        if (!$run) {
            return ['error' => 'Run not found'];
        }

        $pdo->prepare("UPDATE judging_runs SET status = 'RUNNING', started_at = NOW() WHERE id = ?")->execute([$runId]);

        if ($simulateDown || (defined('JUDGE_SANDBOX_SIMULATE_DOWN') && JUDGE_SANDBOX_SIMULATE_DOWN)) {
            $pdo->prepare("
                UPDATE judging_runs
                SET status = 'SYS_ERROR', verdict = 'SYS_ERR', system_error_detail = 'Isolated Runner Connection Refused', finished_at = NOW()
                WHERE id = ?
            ")->execute([$runId]);
            self::syncLatestRunToSubmission($pdo, (int)$run['submission_id'], $runId, 'SYS_ERR', 0, 0, 0);
            return ['verdict' => 'SYS_ERR'];
        }

        $workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cl_oj_run_' . $runId . '_' . uniqid();
        @mkdir($workDir, 0700, true);

        $compileRes = self::compileOrCheckSyntax($run['language'], $run['source_code'], $workDir);
        if (!$compileRes['ok']) {
            $verdict = $compileRes['type'] === 'CE' ? 'CE' : 'SYS_ERR';
            $status  = $verdict === 'CE' ? 'COMPLETED' : 'SYS_ERROR';
            $pdo->prepare("
                UPDATE judging_runs SET status = ?, verdict = ?, compiler_output = ?, finished_at = NOW() WHERE id = ?
            ")->execute([$status, $verdict, $compileRes['message'], $runId]);
            self::syncLatestRunToSubmission($pdo, (int)$run['submission_id'], $runId, $verdict, 0, 0, 0);
            self::cleanupDir($workDir);
            return ['verdict' => $verdict];
        }

        $stmtCases = $pdo->prepare("SELECT * FROM test_cases WHERE problem_version_id = ? ORDER BY case_order ASC");
        $stmtCases->execute([$run['problem_version_id']]);
        $cases = $stmtCases->fetchAll();

        $totalCases = count($cases);
        $passCount = 0;
        $totalScore = 0;
        $maxTimeMs = 0;
        $overallVerdict = 'AC';

        $pdo->prepare("DELETE FROM case_results WHERE judging_run_id = ?")->execute([$runId]);
        $insCaseRes = $pdo->prepare("
            INSERT INTO case_results (judging_run_id, test_case_id, verdict, execution_time_ms, exit_code, actual_output, runtime_error)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($cases as $tc) {
            $exec = self::runSingleTestCase($compileRes['exec_cmd'], $tc['stdin_data'], (int)$run['time_limit_ms']);
            $maxTimeMs = max($maxTimeMs, $exec['time_ms']);

            if ($exec['verdict'] === 'OK') {
                $actualNorm   = self::normalizeOutput($exec['stdout']);
                $expectedNorm = self::normalizeOutput($tc['expected_output']);
                if ($actualNorm === $expectedNorm) {
                    $caseVerdict = 'AC';
                    $passCount++;
                    $totalScore += (int)$tc['points'];
                } else {
                    $caseVerdict = 'WA';
                }
            } else {
                $caseVerdict = $exec['verdict'];
            }

            if ($caseVerdict !== 'AC' && $overallVerdict === 'AC') {
                $overallVerdict = $caseVerdict;
            }

            $insCaseRes->execute([$runId, $tc['id'], $caseVerdict, $exec['time_ms'], $exec['exit_code'], $exec['stdout'], $exec['stderr']]);
        }

        $runStatus = ($overallVerdict === 'SYS_ERR') ? 'SYS_ERROR' : 'COMPLETED';
        $pdo->prepare("
            UPDATE judging_runs
            SET status = ?, verdict = ?, score = ?, pass_count = ?, total_cases = ?, max_time_ms = ?, finished_at = NOW()
            WHERE id = ?
        ")->execute([$runStatus, $overallVerdict, $totalScore, $passCount, $totalCases, $maxTimeMs, $runId]);

        self::syncLatestRunToSubmission($pdo, (int)$run['submission_id'], $runId, $overallVerdict, $totalScore, $passCount, $totalCases);

        // Cộng XP khi học viên giải đúng một bài LẦN ĐẦU (first-AC): 10 XP × độ khó
        // Easy=10, Medium=20, Hard=30. Không cộng lại cho lần AC tiếp theo cùng bài.
        if ($overallVerdict === 'AC') {
            self::awardFirstSolveXp($pdo, (int)$run['submission_id']);
        }

        self::cleanupDir($workDir);
        return ['verdict' => $overallVerdict, 'score' => $totalScore, 'pass_count' => $passCount, 'total_cases' => $totalCases];
    }

    // Kết quả của lần chấm cũ về sau KHÔNG ghi đè kết quả lần mới
    public static function syncLatestRunToSubmission(PDO $pdo, int $submissionId, int $runId, string $verdict, int $score, int $passCount, int $totalCases): bool
    {
        $stmt = $pdo->prepare("
            UPDATE submissions
            SET latest_run_id = :run_id, final_verdict = :verdict, final_score = :score,
                pass_count = :pass_count, total_cases = :total_cases
            WHERE id = :sub_id AND (latest_run_id IS NULL OR latest_run_id <= :run_id_check)
        ");
        $stmt->execute([
            ':run_id' => $runId, ':verdict' => $verdict, ':score' => $score,
            ':pass_count' => $passCount, ':total_cases' => $totalCases,
            ':sub_id' => $submissionId, ':run_id_check' => $runId,
        ]);
        return $stmt->rowCount() > 0;
    }

    private static function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $file) {
            if ($file !== '.' && $file !== '..') {
                @unlink($dir . DIRECTORY_SEPARATOR . $file);
            }
        }
        @rmdir($dir);
    }

    // Cộng XP first-AC cho chủ bài nộp. Trả về số XP đã cộng (0 nếu đã từng AC bài này).
    public static function awardFirstSolveXp(PDO $pdo, int $submissionId): int
    {
        $stmt = $pdo->prepare("
            SELECT s.user_id, s.problem_id, p.difficulty
            FROM submissions s JOIN problems p ON p.id = s.problem_id
            WHERE s.id = ?
        ");
        $stmt->execute([$submissionId]);
        $sub = $stmt->fetch();
        if (!$sub) {
            return 0;
        }
        $chk = $pdo->prepare("
            SELECT COUNT(*) FROM submissions
            WHERE user_id = ? AND problem_id = ? AND final_verdict = 'AC' AND id <> ?
        ");
        $chk->execute([$sub['user_id'], $sub['problem_id'], $submissionId]);
        if ((int)$chk->fetchColumn() > 0) {
            return 0; // đã từng AC bài này trước đó
        }
        $xp = ['Easy' => 10, 'Medium' => 20, 'Hard' => 30][$sub['difficulty']] ?? 10;
        $pdo->prepare("UPDATE users SET xp_points = xp_points + ? WHERE id = ?")->execute([$xp, $sub['user_id']]);
        return $xp;
    }
}
