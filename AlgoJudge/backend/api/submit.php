<?php
// backend/api/submit.php — Nhận bài nộp, đưa vào hàng đợi. Chống nộp trùng idempotency.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/JudgeEngine.php';
require_once __DIR__ . '/../lib/JudgeQueue.php';

// This also rejects methods other than POST, before reading the body or using DB.
csrf_verify_api();
$user = Auth::requireApiLogin();
$raw = file_get_contents('php://input', false, null, 0, 2097153);
$data = is_string($raw) && strlen($raw) <= 2097152 ? json_decode($raw, true) : null;
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Dữ liệu JSON không hợp lệ hoặc quá lớn.']);
    exit;
}

$rawProblemId   = $data['problem_id'] ?? null;
$problemId      = is_int($rawProblemId) || is_string($rawProblemId)
    ? filter_var($rawProblemId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) : false;
$language       = $data['language'] ?? null;
$sourceCode     = $data['source_code'] ?? '';
$rawKey         = $data['idempotency_key'] ?? null;
$idempotencyKey = is_string($rawKey) ? trim($rawKey) : '';

if (!is_string($language) || !JudgeEngine::isSupported($language)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ngôn ngữ không được hỗ trợ.']);
    exit;
}
if (!is_string($sourceCode) || strlen($sourceCode) > DockerSandbox::MAX_SOURCE_BYTES) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Mã nguồn tối đa 256 KiB.']);
    exit;
}
// submissions.idempotency_key is VARCHAR(64); never truncate a retry key or source.
if ($problemId === false || trim($sourceCode) === '' || $idempotencyKey === '' || strlen($idempotencyKey) > 64) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Thông tin bài nộp hoặc khóa chống trùng (tối đa 64 byte) không hợp lệ.']);
    exit;
}

// Both the early retry and a concurrent unique-key conflict return only OWNED work.
$replyExistingSubmission = static function () use ($pdo, $idempotencyKey, $user): bool {
    $stmt = $pdo->prepare('SELECT id, latest_run_id, problem_version_id FROM submissions WHERE idempotency_key = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$idempotencyKey, $user['id']]);
    $existing = $stmt->fetch();
    if (!$existing) return false;
    echo json_encode([
        'ok' => true,
        'duplicate_prevented' => true,
        'submission_id' => (int)$existing['id'],
        'run_id' => (int)$existing['latest_run_id'],
        'problem_version_id' => (int)$existing['problem_version_id'],
        'message' => 'Yêu cầu gửi lại đã được nhận diện (Idempotent): trả về mã bài nộp hiện có.',
    ]);
    return true;
};

try {
    if ($replyExistingSubmission()) exit;
    $stmtProb = $pdo->prepare('SELECT current_version_id FROM problems WHERE id = ? AND is_active = 1');
    $stmtProb->execute([$problemId]);
    $prob = $stmtProb->fetch();
    if (!$prob || !$prob['current_version_id']) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Không tìm thấy bài tập hoặc phiên bản bộ dữ liệu.']);
        exit;
    }
    $versionId = (int)$prob['current_version_id'];

    $pdo->beginTransaction();
    $stmtSub = $pdo->prepare("
        INSERT INTO submissions (idempotency_key, user_id, problem_id, problem_version_id, language, source_code, final_verdict)
        VALUES (?, ?, ?, ?, ?, ?, 'QUEUED')
    ");
    $stmtSub->execute([$idempotencyKey, $user['id'], $problemId, $versionId, $language, $sourceCode]);
    $submissionId = (int)$pdo->lastInsertId();

    $stmtRun = $pdo->prepare("
        INSERT INTO judging_runs (submission_id, problem_version_id, run_number, trigger_type, triggered_by, status, verdict)
        VALUES (?, ?, 1, 'INITIAL', ?, 'QUEUED', 'PENDING')
    ");
    $stmtRun->execute([$submissionId, $versionId, $user['id']]);
    $runId = (int)$pdo->lastInsertId();

    $pdo->prepare('UPDATE submissions SET latest_run_id = ? WHERE id = ?')->execute([$runId, $submissionId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // A second request can pass the first SELECT before the first one commits.
    // Roll back before re-reading, so InnoDB's earlier snapshot is no longer used.
    if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
        try {
            if ($replyExistingSubmission()) exit;
        } catch (Throwable $lookupError) {
            // Keep internal SQL/details out of the API response.
        }
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Không thể khởi tạo bài nộp.']);
    exit;
}

// Release this user's session lock before any synchronous Docker judging fallback.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
// Đánh thức worker; nếu daemon chết hẳn thì chấm luôn trong request (fallback)
// để bài nộp không kẹt QUEUED — vẫn chạy trong Docker, không chạy code trên host.
try {
    JudgeQueue::ensureJudgingRunProcessed($pdo, $runId);
} catch (Throwable $e) {
    // The submission is committed; dispatch failure does not mean rejection.
    // Return its identifiers without retrying or assuming the current run status.
    error_log('Accepted submission dispatch unavailable; operator recovery may be required.');
}

echo json_encode([
    'ok' => true,
    'duplicate_prevented' => false,
    'submission_id' => $submissionId,
    'run_id' => $runId,
    'problem_version_id' => $versionId,
]);
