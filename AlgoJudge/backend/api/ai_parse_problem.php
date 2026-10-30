<?php
// backend/api/ai_parse_problem.php — Nhận đề thô → trả khung bài tập có cấu trúc (chưa lưu DB).
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/AIHelper.php';

header('Content-Type: application/json; charset=utf-8');
$user = Auth::requireRole(['teacher', 'admin']);

try {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $raw = trim((string)($body['text'] ?? ''));
    if ($raw === '') {
        throw new Exception('Hãy dán đề bài vào trước.');
    }
    $result = AIHelper::parseProblem($raw);
    echo json_encode(['ok' => true, 'data' => $result['data'], 'source' => $result['source'],
        'ai_ready' => AIHelper::hasKey()], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
