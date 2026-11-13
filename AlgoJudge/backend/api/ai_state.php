<?php
require_once __DIR__ . '/../lib/ChatbotHttp.php';
$user = Auth::requireApiLogin();
header('Cache-Control: no-store, private');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') throw new ChatbotError('Chỉ chấp nhận GET.', 'method', 405);
    $store = ChatbotHttp::store($user);
    ChatbotHttp::json(['ok' => true] + $store->state());
} catch (Throwable $e) { $error = ChatbotHttp::error($e); ChatbotHttp::json($error, $error['status']); }
