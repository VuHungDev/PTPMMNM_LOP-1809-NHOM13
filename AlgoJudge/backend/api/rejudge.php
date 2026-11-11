<?php
// backend/api/rejudge.php — Chấm lại 1 bài nộp trên phiên bản bộ test mới (giữ lịch sử cũ)
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

$submissionId    = (int)($data['submission_id'] ?? 0);
$targetVersionId = (int)($data['problem_version_id'] ?? 0);
$ownsTransaction = false;
try {
    if ($pdo->inTransaction()) throw new LogicException('Judging request requires its own transaction.');
    $pdo->beginTransaction();
    $ownsTransaction = true;
    // Both rejudge and retry lock the submission before checking/allocating runs.
    $stmtSub = $pdo->prepare('SELECT * FROM submissions WHERE id = ? FOR UPDATE');
    $stmtSub->execute([$submissionId]);
    $sub = $stmtSub->fetch();
    if (!$sub) throw new DomainException('Không tìm thấy bài nộp để chấm lại.', 404);
    if (!Auth::canViewSubmission($user, ['id' => (int)$sub['user_id']])) {
        throw new DomainException('Bạn không quản lý lớp của bài nộp này.', 403);
    }

    if ($targetVersionId <= 0) {
        $stmtP = $pdo->prepare('SELECT current_version_id FROM problems WHERE id = ?');
        $stmtP->execute([$sub['problem_id']]);
        $targetVersionId = (int)$stmtP->fetchColumn();
    }
    $stmtVersion = $pdo->prepare('SELECT version_number FROM problem_versions WHERE id = ? AND problem_id = ?');
    $stmtVersion->execute([$targetVersionId, (int)$sub['problem_id']]);
    $versionNumber = $stmtVersion->fetchColumn();
    if ($versionNumber === false) {
        throw new DomainException('Phiên bản bộ test không thuộc bài tập của bài nộp này.', 400);
    }

    $stmtDup = $pdo->prepare("SELECT id FROM judging_runs WHERE submission_id = ? AND status IN ('QUEUED', 'RUNNING') LIMIT 1 FOR UPDATE");
    $stmtDup->execute([$submissionId]);
    if ($stmtDup->fetch()) {
        throw new DomainException('Bài nộp này đang có một lượt chấm khác chờ/xử lý. Vui lòng đợi xong rồi mới chấm lại.', 409);
    }
    $stmtMax = $pdo->prepare('SELECT COALESCE(MAX(run_number), 0) + 1 FROM judging_runs WHERE submission_id = ?');
    $stmtMax->execute([$submissionId]);
    $nextRunNum = (int)$stmtMax->fetchColumn();
    $stmtIns = $pdo->prepare("INSERT INTO judging_runs (submission_id, problem_version_id, run_number, trigger_type, triggered_by, status, verdict) VALUES (?, ?, ?, 'REJUDGE', ?, 'QUEUED', 'PENDING')");
    $stmtIns->execute([$submissionId, $targetVersionId, $nextRunNum, $user['id']]);
    $newRunId = (int)$pdo->lastInsertId();

    // This event records accepted work, not its eventual judge verdict.
    AuditLog::record($pdo, $user, 'judge.rejudge', 'run', $newRunId, [
        'submission_id' => $submissionId, 'problem_id' => (int)$sub['problem_id'],
        'version_number' => (int)$versionNumber, 'source' => 'api',
    ]);
    $pdo->commit();
    $ownsTransaction = false;
} catch (Throwable $e) {
    if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
    $status = $e instanceof DomainException && in_array($e->getCode(), [400, 403, 404, 409], true) ? $e->getCode() : 500;
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $e instanceof DomainException ? $e->getMessage() : 'Không thể gửi yêu cầu chấm lại. Chưa có thay đổi nào được lưu.']);
    exit;
}

// Never dispatch while the queue row or its audit event is uncommitted.
$res = JudgeEngine::processJudgingRun($pdo, $newRunId);
echo json_encode([
    'ok' => true,
    'new_run_id' => $newRunId,
    'run_number' => $nextRunNum,
    'verdict' => $res['verdict'] ?? 'SYS_ERR',
    'score' => $res['score'] ?? 0,
]);
