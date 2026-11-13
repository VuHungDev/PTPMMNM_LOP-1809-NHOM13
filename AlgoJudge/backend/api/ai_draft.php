<?php
require_once __DIR__ . '/../lib/ChatbotHttp.php';
$user = Auth::requireApiLogin();
csrf_verify_api();
try {
    $body = ChatbotHttp::body();
    $action = $body['action'] ?? '';
    $store = ChatbotHttp::store($user);
    if ($action === 'stop') { $store->stop(); ChatbotHttp::json(['ok' => true]); exit; }
    ChatbotHttp::requireStaff($user);
    if (!in_array($action, ['update','review','cancel'], true)) throw new ChatbotError('Thao tác bản nháp không hợp lệ.');
    $id = is_string($body['draft_id'] ?? null) ? $body['draft_id'] : '';
    $revision = (int)($body['revision'] ?? 0);
    $draft = $store->mutate($id, $revision, static function ($draft) use ($body, $action) {
        if ($action === 'cancel') { $draft['status'] = 'cancelled'; $draft['revision']++; return $draft; }
        if ($action === 'review') {
            $issues = ChatbotDraft::issues($draft['data']);
            if ($issues) throw new ChatbotError('Bản nháp còn mục chưa hợp lệ. Hãy sửa các mục được liệt kê.', 'draft_validation', 422, $issues);
            $draft['semantic_review'] = false; $draft['revision']++; $draft['save_key'] = bin2hex(random_bytes(24));
            return $draft;
        }
        if (!is_array($body['data'] ?? null)) throw new ChatbotError('Thiếu object dữ liệu bản nháp.');
        $data = ChatbotDraft::normalize($body['data']);
        $semanticFields = ['description','input_desc','output_desc','sample_input','sample_output','test_cases'];
        foreach ($semanticFields as $field) if ($data[$field] !== $draft['data'][$field]) $draft['semantic_review'] = true;
        $draft['data'] = $data; $draft['issues'] = ChatbotDraft::issues($data);
        $draft['revision']++; $draft['save_key'] = bin2hex(random_bytes(24));
        return $draft;
    });
    ChatbotHttp::json(['ok' => true, 'draft' => $draft]);
} catch (Throwable $e) { $error = ChatbotHttp::error($e); ChatbotHttp::json($error, $error['status']); }
