<?php
// backend/api/ai_chat.php — Endpoint chat thống nhất: tạo bài | hỏi đáp | tra cứu | xác nhận thao tác.
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/AIAssistant.php';
require_once __DIR__ . '/../lib/AIHelper.php';

header('Content-Type: application/json; charset=utf-8');
$user = Auth::requireApiLogin(); // mọi vai trò đều chat được; từng thao tác tự kiểm tra quyền

try {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    // Nhánh 1: người dùng bấm "Đồng ý" cho thao tác đã hỏi trước đó
    if (!empty($body['confirm_token'])) {
        $res = AIAssistant::execute($user, (string)$body['confirm_token']);
        echo json_encode(['ok' => true, 'kind' => 'done', 'answer' => $res['answer']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $text = trim((string)($body['text'] ?? ''));
    if ($text === '') throw new Exception('Hãy nhập nội dung trước.');

    $r = AIAssistant::route($user, $text);

    // Nhánh 2: dán đề dài → bóc tách khung bài (giữ luồng tạo bài cũ)
    if ($r['kind'] === 'parse_problem') {
        $parsed = AIHelper::parseProblem($text);
        echo json_encode(['ok' => true, 'kind' => 'parse_problem',
            'data' => $parsed['data'], 'source' => $parsed['source'],
            'ai_ready' => AIHelper::hasKey()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Nhánh 3: số liệu nhanh từ DB
    if ($r['kind'] === 'stats') {
        echo json_encode(['ok' => true, 'kind' => 'answer', 'answer' => $r['answer']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Nhánh 4: thao tác GHI → trả tóm tắt + token để hỏi xác nhận (chưa làm gì cả)
    if ($r['kind'] === 'confirm') {
        echo json_encode(['ok' => true, 'kind' => 'confirm', 'token' => $r['token'],
            'action' => $r['action'], 'summary' => $r['summary']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Nhánh 5: hỏi đáp tự do
    $answer = $r['kind'] === 'qa' && isset($r['answer'])
        ? $r['answer']           // route đã trả sẵn (vd: thiếu info, không đủ quyền)
        : AIAssistant::answerQA($user, $text);
    echo json_encode(['ok' => true, 'kind' => 'answer', 'answer' => $answer], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
