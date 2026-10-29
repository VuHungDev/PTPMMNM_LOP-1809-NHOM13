<?php
// backend/api/submission_status.php — Tra cứu kết quả + lịch sử runs (ẩn test kín)
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/Auth.php';

$viewer = Auth::requireApiLogin();
$submissionId = (int)($_GET['submission_id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT s.*, u.id AS owner_id, u.username, u.full_name, u.class_code, pv.version_number
    FROM submissions s
    JOIN users u ON u.id = s.user_id
    JOIN problem_versions pv ON pv.id = s.problem_version_id
    WHERE s.id = ?
");
$stmt->execute([$submissionId]);
$sub = $stmt->fetch();

if (!$sub) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Không tìm thấy bài nộp.']);
    exit;
}

$owner = ['id' => $sub['owner_id'], 'class_code' => $sub['class_code']];
if (!Auth::canViewSubmission($viewer, $owner)) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => '403 Forbidden: Bạn không có quyền đọc mã nguồn hoặc chi tiết bài nộp của học viên khác!',
    ]);
    exit;
}

$stmtRuns = $pdo->prepare("
    SELECT jr.id, jr.run_number, jr.trigger_type, jr.status, jr.verdict, jr.score,
           jr.pass_count, jr.total_cases, jr.max_time_ms, jr.compiler_output,
           jr.started_at, jr.finished_at, pv.version_number
    FROM judging_runs jr
    JOIN problem_versions pv ON pv.id = jr.problem_version_id
    WHERE jr.submission_id = ?
    ORDER BY jr.id DESC
");
$stmtRuns->execute([$submissionId]);
$runs = $stmtRuns->fetchAll();

$targetRunId = (int)($_GET['run_id'] ?? $sub['latest_run_id']);
$stmtCases = $pdo->prepare("
    SELECT cr.verdict, cr.execution_time_ms, cr.actual_output, cr.runtime_error,
           tc.case_order, tc.is_public, tc.is_boundary, tc.stdin_data, tc.expected_output, tc.points
    FROM case_results cr
    JOIN test_cases tc ON tc.id = cr.test_case_id
    WHERE cr.judging_run_id = ?
    ORDER BY tc.case_order ASC
");
$stmtCases->execute([$targetRunId]);
$rawCases = $stmtCases->fetchAll();

$safeCases = [];
foreach ($rawCases as $c) {
    $isPublic = (int)$c['is_public'] === 1;
    $safeCases[] = [
        'case_order'        => (int)$c['case_order'],
        'is_public'         => $isPublic,
        'is_boundary'       => (int)$c['is_boundary'] === 1,
        'verdict'           => $c['verdict'],
        'execution_time_ms' => (int)$c['execution_time_ms'],
        'points'            => (int)$c['points'],
        'stdin_preview'     => $isPublic ? $c['stdin_data'] : '[Dữ liệu chấm kín — Đã ẩn bảo mật]',
        'expected_preview'  => $isPublic ? $c['expected_output'] : '[Đáp án chấm kín — Đã ẩn bảo mật]',
        'actual_preview'    => $isPublic ? mb_substr((string)$c['actual_output'], 0, 300) : '[Đầu ra bị ẩn trên bộ test kín]',
    ];
}

echo json_encode([
    'ok' => true,
    'submission' => [
        'id'             => (int)$sub['id'],
        'username'       => $sub['username'],
        'class_code'     => $sub['class_code'],
        'language'       => $sub['language'],
        'source_code'    => $sub['source_code'],
        'version_number' => (int)$sub['version_number'],
        'latest_run_id'  => (int)$sub['latest_run_id'],
        'final_verdict'  => $sub['final_verdict'],
        'final_score'    => (int)$sub['final_score'],
        'pass_count'     => (int)$sub['pass_count'],
        'total_cases'    => (int)$sub['total_cases'],
        'created_at'     => $sub['created_at'],
    ],
    'runs_history' => $runs,
    'active_run_cases' => $safeCases,
]);
