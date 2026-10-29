<?php
// backend/api/submit.php — Nhận bài nộp, đưa vào hàng đợi. Chống nộp trùng idempotency.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/JudgeEngine.php';
require_once __DIR__ . '/../lib/JudgeQueue.php';

$user = Auth::requireApiLogin();
$data = json_decode(file_get_contents('php://input'), true);

$problemId      = (int)($data['problem_id'] ?? 0);
$language       = $data['language'] ?? 'python';
$sourceCode     = $data['source_code'] ?? '';
$idempotencyKey = trim($data['idempotency_key'] ?? '');

if (!JudgeEngine::isSupported($language)) {
    $language = 'python';
}
if ($problemId <= 0 || trim($sourceCode) === '' || $idempotencyKey === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Thiếu thông tin bài nộp hoặc khóa chống trùng (idempotency_key).']);
    exit;
}

$stmtCheck = $pdo->prepare("SELECT id, latest_run_id, problem_version_id FROM submissions WHERE idempotency_key = ? AND user_id = ? LIMIT 1");
$stmtCheck->execute([$idempotencyKey, $user['id']]);
$existingSub = $stmtCheck->fetch();

if ($existingSub) {
    echo json_encode([
        'ok' => true,
        'duplicate_prevented' => true,
        'submission_id' => (int)$existingSub['id'],
        'run_id' => (int)$existingSub['latest_run_id'],
        'problem_version_id' => (int)$existingSub['problem_version_id'],
        'message' => 'Yêu cầu gửi lại đã được nhận diện (Idempotent): trả về mã bài nộp hiện có.',
    ]);
    exit;
}

$stmtProb = $pdo->prepare("SELECT current_version_id FROM problems WHERE id = ? AND is_active = 1");
$stmtProb->execute([$problemId]);
$prob = $stmtProb->fetch();
if (!$prob || !$prob['current_version_id']) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Không tìm thấy bài tập hoặc phiên bản bộ dữ liệu.']);
    exit;
}
$versionId = (int)$prob['current_version_id'];

$pdo->beginTransaction();
try {
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

    $pdo->prepare("UPDATE submissions SET latest_run_id = ? WHERE id = ?")->execute([$runId, $submissionId]);
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Không thể khởi tạo bài nộp.']);
    exit;
}

// Đánh thức worker; nếu daemon chết hẳn thì chấm luôn trong request (fallback)
// để bài nộp không kẹt QUEUED — vẫn chạy trong tiến trình OS con riêng.
JudgeQueue::ensureJudgingRunProcessed($pdo, $runId);

echo json_encode([
    'ok' => true,
    'duplicate_prevented' => false,
    'submission_id' => $submissionId,
    'run_id' => $runId,
    'problem_version_id' => $versionId,
]);
