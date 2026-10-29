<?php
// backend/api/run_custom.php — Chạy thử với dữ liệu tự nhập (qua hàng đợi, không chạy trong Web)
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/JudgeEngine.php';
require_once __DIR__ . '/../lib/JudgeQueue.php';

$user = Auth::requireApiLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $language   = $data['language'] ?? 'python';
    $sourceCode = $data['source_code'] ?? '';
    $stdin      = $data['stdin'] ?? '';

    if (!JudgeEngine::isSupported($language)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ngôn ngữ không được hỗ trợ.']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO custom_runs (user_id, language, source_code, custom_stdin, status) VALUES (?, ?, ?, ?, 'QUEUED')");
    $stmt->execute([$user['id'], $language, $sourceCode, $stdin]);
    $customRunId = (int)$pdo->lastInsertId();

    $daemon = BE_DIR . '/worker/judge_daemon.php';
    $phpBin = PHP_BINARY;
    $cliCmd = (PHP_OS_FAMILY === 'Windows')
        ? 'start /B "" ' . $phpBin . ' ' . escapeshellarg($daemon) . ' --once > NUL 2>&1'
        : $phpBin . ' ' . escapeshellarg($daemon) . ' --once > /dev/null 2>&1 &';
    @pclose(@popen($cliCmd, 'r'));

    // Fallback: daemon chết thì chấm luôn trong request (custom run chỉ ~2s).
    if (!JudgeQueue::isDaemonAlive($pdo)) {
        JudgeQueue::processCustomRun($pdo, $customRunId);
    }

    echo json_encode(['ok' => true, 'custom_run_id' => $customRunId]);
    exit;
}

$customId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT id, status, verdict, stdout_output, stderr_output, execution_time_ms, error_line, error_message, error_hint FROM custom_runs WHERE id = ? AND user_id = ?");
$stmt->execute([$customId, $user['id']]);
$row = $stmt->fetch();

echo json_encode(['ok' => (bool)$row, 'data' => $row]);
