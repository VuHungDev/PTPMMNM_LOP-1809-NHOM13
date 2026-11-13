<?php
/** The sole chatbot write entrypoint: explicit click, owned reviewed draft, idempotent transaction. */
require_once __DIR__ . '/../lib/ChatbotHttp.php';
$user = Auth::requireApiRole(['teacher','admin']);
csrf_verify_api();
try {
    $body = ChatbotHttp::body();
    $id = is_string($body['draft_id'] ?? null) ? $body['draft_id'] : '';
    $key = is_string($body['idempotency_key'] ?? null) ? $body['idempotency_key'] : '';
    if ($id === '' || $key === '') throw new ChatbotError('Chỉ tạo bài từ bản nháp đã duyệt bằng nút Thêm bài trong chatbot.', 'draft_required', 422);
    $store = ChatbotHttp::store($user);
    session_write_close(); // Database actor/draft locks enforce the short, idempotent save.
    $draft = $store->save($id, (int)($body['revision'] ?? 0), $key);
    ChatbotHttp::json(['ok' => true, 'problem_id' => $draft['problem_id'], 'slug' => $draft['data']['slug'], 'edit_url' => $draft['edit_url'], 'draft' => $draft]);
} catch (Throwable $e) { $error = ChatbotHttp::error($e); ChatbotHttp::json($error, $error['status']); }
