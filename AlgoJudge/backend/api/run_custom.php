<?php
// backend/api/run_custom.php — Chạy thử với dữ liệu tự nhập (qua hàng đợi, không chạy trong Web)
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/JudgeEngine.php';
require_once __DIR__ . '/../lib/JudgeQueue.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Chỉ chấp nhận GET hoặc POST.']);
    exit;
}
if ($method === 'POST') csrf_verify_api();
$user = Auth::requireApiLogin();

if ($method === 'POST') {
    $raw = file_get_contents('php://input', false, null, 0, 2097153);
    $data = is_string($raw) && strlen($raw) <= 2097152 ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Dữ liệu JSON không hợp lệ hoặc quá lớn.']);
        exit;
    }
    $language   = $data['language'] ?? null;
    $sourceCode = $data['source_code'] ?? null;
    $stdin      = array_key_exists('stdin', $data) ? $data['stdin'] : '';

    if (!is_string($language) || !JudgeEngine::isSupported($language)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ngôn ngữ không được hỗ trợ.']);
        exit;
    }

    if (!is_string($sourceCode) || !is_string($stdin) || strlen($sourceCode) > DockerSandbox::MAX_SOURCE_BYTES || strlen($stdin) > 60000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Mã nguồn tối đa 256 KiB; dữ liệu chạy thử tối đa 60.000 byte.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO custom_runs (user_id, language, source_code, custom_stdin, status) VALUES (?, ?, ?, ?, 'QUEUED')");
        $stmt->execute([$user['id'], $language, $sourceCode, $stdin]);
        $customRunId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Không thể khởi tạo phiên chạy.']);
        exit;
    }

    // The same Docker-isolated path and 10s runtime deadline in daemon and fallback.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    try {
        JudgeQueue::ensureCustomRunProcessed($pdo, $customRunId);
    } catch (Throwable $e) {
        // The row is already saved; let the client track it, never create a retry.
        // Dispatch may have partially completed, so do not claim a current status.
        error_log('Accepted custom-run dispatch unavailable; operator recovery may be required.');
    }

    echo json_encode(['ok' => true, 'custom_run_id' => $customRunId]);
    exit;
}

$rawId = $_GET['id'] ?? null;
$customId = is_int($rawId) || is_string($rawId)
    ? filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) : false;
if ($customId === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Mã phiên chạy không hợp lệ.']);
    exit;
}
try {
    $stmt = $pdo->prepare("SELECT id, status, verdict, stdout_output, stderr_output, execution_time_ms, error_line, error_message, error_hint FROM custom_runs WHERE id = ? AND user_id = ?");
    $stmt->execute([$customId, $user['id']]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Không thể đọc kết quả phiên chạy.']);
    exit;
}

echo json_encode(['ok' => (bool)$row, 'data' => $row]);
