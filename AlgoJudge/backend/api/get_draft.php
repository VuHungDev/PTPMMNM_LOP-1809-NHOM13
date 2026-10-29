<?php
// backend/api/get_draft.php — Lấy bản nháp đã lưu của người học cho 1 bài
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/Auth.php';

$user = Auth::requireApiLogin();
$problemId = (int)($_GET['problem_id'] ?? 0);

$stmt = $pdo->prepare("SELECT language, source_code FROM drafts WHERE user_id = ? AND problem_id = ?");
$stmt->execute([$user['id'], $problemId]);
$drafts = [];
foreach ($stmt->fetchAll() as $row) {
    $drafts[$row['language']] = $row['source_code'];
}

echo json_encode(['ok' => true, 'drafts' => $drafts]);
