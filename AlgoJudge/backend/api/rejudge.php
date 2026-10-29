<?php
// backend/api/rejudge.php — Chấm lại 1 bài nộp trên phiên bản bộ test mới (giữ lịch sử cũ)
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/JudgeEngine.php';

$user = Auth::requireApiRole(['teacher', 'admin']);
$data = json_decode(file_get_contents('php://input'), true);

$submissionId    = (int)($data['submission_id'] ?? 0);
$targetVersionId = (int)($data['problem_version_id'] ?? 0);

$stmtSub = $pdo->prepare("SELECT * FROM submissions WHERE id = ?");
$stmtSub->execute([$submissionId]);
$sub = $stmtSub->fetch();
if (!$sub) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Không tìm thấy bài nộp để chấm lại.']);
    exit;
}

if ($targetVersionId <= 0) {
    $stmtP = $pdo->prepare("SELECT current_version_id FROM problems WHERE id = ?");
    $stmtP->execute([$sub['problem_id']]);
    $targetVersionId = (int)$stmtP->fetchColumn();
}

$stmtMax = $pdo->prepare("SELECT COALESCE(MAX(run_number), 0) + 1 FROM judging_runs WHERE submission_id = ?");
$stmtMax->execute([$submissionId]);
$nextRunNum = (int)$stmtMax->fetchColumn();

// Chống bấm Chấm lại trùng khi đã có run đang chờ/xử lý cho cùng bài nộp.
$stmtDup = $pdo->prepare("
    SELECT id FROM judging_runs
    WHERE submission_id = ? AND status IN ('QUEUED', 'RUNNING')
    LIMIT 1
");
$stmtDup->execute([$submissionId]);
if ($stmtDup->fetch()) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'Bài nộp này đang có một lượt chấm khác chờ/xử lý. Vui lòng đợi xong rồi mới chấm lại.']);
    exit;
}

$stmtIns = $pdo->prepare("
    INSERT INTO judging_runs (submission_id, problem_version_id, run_number, trigger_type, triggered_by, status, verdict)
    VALUES (?, ?, ?, 'REJUDGE', ?, 'QUEUED', 'PENDING')
");
$stmtIns->execute([$submissionId, $targetVersionId, $nextRunNum, $user['id']]);
$newRunId = (int)$pdo->lastInsertId();

$res = JudgeEngine::processJudgingRun($pdo, $newRunId);

echo json_encode([
    'ok' => true,
    'new_run_id' => $newRunId,
    'run_number' => $nextRunNum,
    'verdict' => $res['verdict'] ?? 'SYS_ERR',
    'score' => $res['score'] ?? 0,
]);
