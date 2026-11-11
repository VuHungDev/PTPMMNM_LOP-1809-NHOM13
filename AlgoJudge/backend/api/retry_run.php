<?php
// backend/api/retry_run.php — Thử lại một lần chấm bị lỗi hệ thống hoặc kẹt.
// Tạo run SYS_RETRY và đánh dấu run cũ trong cùng giao dịch; giữ lịch sử cũ.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/JudgeEngine.php';
require_once __DIR__ . '/../lib/AuditLog.php';

$user = Auth::requireApiRole(['teacher', 'admin']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Chức năng này chỉ chấp nhận POST.']);
    exit;
}
csrf_verify_api();
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) $data = $_POST;
$runId = (int)($data['run_id'] ?? 0);
$ownsTransaction = false;
try {
    if ($pdo->inTransaction()) throw new LogicException('Judging request requires its own transaction.');
    $pdo->beginTransaction();
    $ownsTransaction = true;
    // Resolve the parent first; all queue entry points lock submission then run.
    $stmtParent = $pdo->prepare('SELECT submission_id FROM judging_runs WHERE id = ?');
    $stmtParent->execute([$runId]);
    $submissionId = (int)$stmtParent->fetchColumn();
    if ($submissionId <= 0) throw new DomainException('Không tìm thấy lần chấm.', 404);
    $stmtSub = $pdo->prepare('SELECT * FROM submissions WHERE id = ? FOR UPDATE');
    $stmtSub->execute([$submissionId]);
    $sub = $stmtSub->fetch();
    if (!$sub) throw new DomainException('Không tìm thấy bài nộp.', 404);
    if (!Auth::canViewSubmission($user, ['id' => (int)$sub['user_id']])) {
        throw new DomainException('Bạn không quản lý lớp của bài nộp này.', 403);
    }
    $stmtRun = $pdo->prepare('SELECT * FROM judging_runs WHERE id = ? AND submission_id = ? FOR UPDATE');
    $stmtRun->execute([$runId, $submissionId]);
    $run = $stmtRun->fetch();
    if (!$run) throw new DomainException('Không tìm thấy lần chấm.', 404);
    if (!in_array($run['status'], ['SYS_ERROR', 'QUEUED', 'RUNNING'], true)) {
        throw new DomainException('Chỉ thử lại được các lần chấm lỗi hệ thống hoặc đang kẹt (QUEUED/RUNNING).', 400);
    }
    $stmtVersion = $pdo->prepare('SELECT version_number FROM problem_versions WHERE id = ? AND problem_id = ?');
    $stmtVersion->execute([(int)$run['problem_version_id'], (int)$sub['problem_id']]);
    $versionNumber = $stmtVersion->fetchColumn();
    if ($versionNumber === false) {
        throw new DomainException('Phiên bản bộ test không thuộc bài tập của bài nộp này.', 400);
    }
    $stmtDup = $pdo->prepare("SELECT id FROM judging_runs WHERE submission_id = ? AND id <> ? AND status IN ('QUEUED', 'RUNNING') LIMIT 1 FOR UPDATE");
    $stmtDup->execute([$submissionId, $runId]);
    if ($stmtDup->fetch()) {
        throw new DomainException('Bài nộp này đang có một lượt chấm khác chờ/xử lý. Vui lòng đợi xong rồi mới thử lại.', 409);
    }
    // A superseded run cannot be retried again, even after its successor finishes.
    $stmtLatest = $pdo->prepare('SELECT id, run_number FROM judging_runs WHERE submission_id = ? ORDER BY run_number DESC, id DESC LIMIT 1 FOR UPDATE');
    $stmtLatest->execute([$submissionId]);
    $latest = $stmtLatest->fetch();
    if (!$latest || (int)$latest['id'] !== $runId) {
        throw new DomainException('Lần chấm này đã được thay thế. Hãy dùng lần chấm mới nhất.', 409);
    }
    $nextRunNum = (int)$latest['run_number'] + 1;
    $stmtIns = $pdo->prepare("INSERT INTO judging_runs (submission_id, problem_version_id, run_number, trigger_type, triggered_by, status, verdict) VALUES (?, ?, ?, 'SYS_RETRY', ?, 'QUEUED', 'PENDING')");
    $stmtIns->execute([$submissionId, $run['problem_version_id'], $nextRunNum, $user['id']]);
    $newRunId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE judging_runs SET status = 'SYS_ERROR', verdict = 'SYS_ERR', system_error_detail = CONCAT(COALESCE(system_error_detail, ''), ' | Superseded by retry run #', ?), finished_at = COALESCE(finished_at, NOW()) WHERE id = ?")
        ->execute([$newRunId, $runId]);
    AuditLog::record($pdo, $user, 'judge.retry', 'run', $newRunId, [
        'previous_run_id' => $runId, 'submission_id' => $submissionId,
        'problem_id' => (int)$sub['problem_id'], 'version_number' => (int)$versionNumber, 'source' => 'api',
    ]);
    $pdo->commit();
    $ownsTransaction = false;
} catch (Throwable $e) {
    if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
    $status = $e instanceof DomainException && in_array($e->getCode(), [400, 403, 404, 409], true) ? $e->getCode() : 500;
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $e instanceof DomainException ? $e->getMessage() : 'Không thể gửi yêu cầu thử lại. Chưa có thay đổi nào được lưu.']);
    exit;
}

// Audit means accepted request; judging starts only after the transaction commits.
$res = JudgeEngine::processJudgingRun($pdo, $newRunId);
echo json_encode([
    'ok' => true,
    'new_run_id' => $newRunId,
    'run_number' => $nextRunNum,
    'verdict' => $res['verdict'] ?? 'SYS_ERR',
    'score' => $res['score'] ?? 0,
]);
