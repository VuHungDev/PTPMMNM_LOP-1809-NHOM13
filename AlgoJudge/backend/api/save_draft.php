<?php
// backend/api/save_draft.php — Lưu bản nháp mã nguồn trên trình duyệt
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/JudgeEngine.php';

$user = Auth::requireApiLogin();
$data = json_decode(file_get_contents('php://input'), true);

$problemId  = (int)($data['problem_id'] ?? 0);
$language   = $data['language'] ?? 'python';
$sourceCode = $data['source_code'] ?? '';

if ($problemId <= 0 || !JudgeEngine::isSupported($language)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Dữ liệu bản nháp không hợp lệ.']);
    exit;
}

$stmt = $pdo->prepare("
    INSERT INTO drafts (user_id, problem_id, language, source_code)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE source_code = VALUES(source_code), updated_at = NOW()
");
$stmt->execute([$user['id'], $problemId, $language, $sourceCode]);

echo json_encode(['ok' => true, 'saved_at' => date('H:i:s')]);
