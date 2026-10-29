<?php
// backend/api/retry_run.php — Thử lại một lần chấm bị lỗi hệ thống (SYS_ERROR) hoặc kẹt (QUEUED/RUNNING quá lâu)
// Tạo judging_runs mới với trigger_type='SYS_RETRY', giữ nguyên toàn bộ lịch sử cũ.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/JudgeEngine.php';

$user = Auth::requireApiRole(['teacher', 'admin']);
$data = json_decode(file_get_contents('php://input'), true);
$runId = (int)($data['run_id'] ?? 0);

$stmtRun = $pdo->prepare("
    SELECT jr.*, s.problem_id FROM judging_runs jr
    JOIN submissions s ON s.id = jr.submission_id
    WHERE jr.id = ?
");
$stmtRun->execute([$runId]);
$run = $stmtRun->fetch();
if (!$run) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Không tìm thấy lần chấm.']);
    exit;
}
if (!in_array($run['status'], ['SYS_ERROR', 'QUEUED', 'RUNNING'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Chỉ thử lại được các lần chấm lỗi hệ thống hoặc đang kẹt (QUEUED/RUNNING).']);
    exit;
}

// Chống bấm Thử lại trùng: nếu đã có run SYS_RETRY/RUNNING khác đang chờ/chấm
// cho cùng submission thì từ chối, tránh 2 run chấm song song gây nhầm lịch sử.
$stmtDup = $pdo->prepare("
    SELECT id FROM judging_runs
    WHERE submission_id = ? AND id <> ? AND status IN ('QUEUED', 'RUNNING')
    LIMIT 1
");
$stmtDup->execute([$run['submission_id'], $runId]);
if ($stmtDup->fetch()) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'Bài nộp này đang có một lượt chấm khác chờ/xử lý. Vui lòng đợi xong rồi mới thử lại.']);
    exit;
}

$stmtMax = $pdo->prepare("SELECT COALESCE(MAX(run_number), 0) + 1 FROM judging_runs WHERE submission_id = ?");
$stmtMax->execute([$run['submission_id']]);
$nextRunNum = (int)$stmtMax->fetchColumn();

$stmtIns = $pdo->prepare("
    INSERT INTO judging_runs (submission_id, problem_version_id, run_number, trigger_type, triggered_by, status, verdict)
    VALUES (?, ?, ?, 'SYS_RETRY', ?, 'QUEUED', 'PENDING')
");
$stmtIns->execute([$run['submission_id'], $run['problem_version_id'], $nextRunNum, $user['id']]);
$newRunId = (int)$pdo->lastInsertId();

// Đánh dấu run cũ đã được thay thế (không xóa — giữ lịch sử)
$pdo->prepare("UPDATE judging_runs SET status = 'SYS_ERROR', verdict = 'SYS_ERR', system_error_detail = CONCAT(COALESCE(system_error_detail, ''), ' | Superseded by retry run #', ?), finished_at = COALESCE(finished_at, NOW()) WHERE id = ? AND status <> 'COMPLETED'")->execute([$newRunId, $runId]);

$res = JudgeEngine::processJudgingRun($pdo, $newRunId);

echo json_encode([
    'ok' => true,
    'new_run_id' => $newRunId,
    'run_number' => $nextRunNum,
    'verdict' => $res['verdict'] ?? 'SYS_ERR',
    'score' => $res['score'] ?? 0,
]);
