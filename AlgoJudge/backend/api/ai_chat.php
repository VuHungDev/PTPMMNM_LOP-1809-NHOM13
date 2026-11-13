<?php
/** New chatbot: read-only help/queries + server-owned drafts. Always streams SSE. */
require_once __DIR__ . '/../lib/ChatbotHttp.php';
require_once __DIR__ . '/../lib/AIAssistant.php';
require_once __DIR__ . '/../lib/ChatbotQueries.php';
$user = Auth::requireApiLogin();
csrf_verify_api();
$streaming = false; $store = null; $token = '';
try {
    $body = ChatbotHttp::body();
    $text = is_string($body['text'] ?? null) ? trim($body['text']) : '';
    if ($text === '' || mb_strlen($text) > 12000) throw new ChatbotError('Hãy nhập nội dung từ 1 đến 12000 ký tự.');
    $store = ChatbotHttp::store($user);
    $draftId = is_string($body['draft_id'] ?? null) ? $body['draft_id'] : '';
    $begin = $store->begin($text, $draftId, (int)($body['revision'] ?? 0));
    $token = $begin['token']; $existing = $begin['draft'];
    $hasDraft = $existing && $existing['status'] === 'review';
    $pending = $begin['pending_source'];
    $route = AIAssistant::route($user, $text, (bool)$hasDraft, !empty($pending));
    $continuing = !empty($route['continue_pending']) && !empty($pending);
    $pendingRevision = $continuing && !empty($pending['draft_id']);
    if ($pendingRevision) {
        if (!$hasDraft || $pending['draft_id'] !== $existing['id'] || (int)$pending['revision'] !== $existing['revision']) {
            throw new ChatbotError('Bản nháp đã thay đổi sau câu hỏi làm rõ. Hãy gửi lại yêu cầu sửa dựa trên bản mới nhất.', 'draft_conflict', 409);
        }
        $route['kind'] = 'revise';
    }
    if ($route['kind'] === 'revise' && ($draftId === '' || !$hasDraft)) throw new ChatbotError('Hãy đồng bộ bản nháp đang xem trước khi yêu cầu sửa.', 'draft_conflict', 409);
    $scope = $_SESSION['chat_scope'];
    ChatbotHttp::releaseSession(); // Configure read-only revalidation before SSE headers are sent.
    ChatbotSSE::start(); $streaming = true;
    $lastGuard = 0;
    $tick = static function () use ($user, $scope, $store, $token, &$lastGuard): void {
        if (connection_aborted()) throw new ChatbotError('Đã ngắt chờ phản hồi.', 'cancelled', 409);
        if (time() - $lastGuard >= 2) {
            ChatbotHttp::guard($user, $scope); $store->heartbeat($token); ChatbotSSE::ping(); $lastGuard = time();
        }
    };
    $tick();
    if (in_array($route['kind'], ['draft','revise'], true)) {
        ChatbotHttp::requireStaff($user);
        $current = $route['kind'] === 'revise' ? $existing : null;
        $source = $current['source'] ?? $text;
        $instruction = $current ? $text : '';
        // Only a tagged clarification reply reuses pending context. Revision
        // questions also retain the original statement and latest owned draft.
        if ($continuing) {
            $clarified = mb_substr($pending['source'] . "\nLàm rõ của người dùng: " . $text, 0, 24000);
            if ($current) $instruction = $clarified;
            else $source = $clarified;
        }
        $result = ChatbotDraft::generate($source, $current['data'] ?? null, $instruction, ['ChatbotSSE','emit'], $tick);
        ChatbotHttp::guard($user, $scope); $store->heartbeat($token);
        if (isset($result['clarification'])) {
            $answer = $result['clarification'];
            $store->finish($token, $answer, [], null, $current, $current ? $instruction : $source);
            ChatbotSSE::text($answer); ChatbotSSE::emit('answer', ['answer' => $answer, 'links' => []]);
        } else {
            $draft = ChatbotDraft::envelope($result['data'], $source, $current);
            $answer = 'Khung bài đã được biên soạn. Hãy kiểm tra các giả định, ví dụ và output của test; chỉ bấm Thêm bài khi đã đúng. Chưa có bài tập nào được tạo.';
            $draft = $store->finish($token, $answer, [], $draft, $current);
            ChatbotSSE::emit('draft', ['draft' => $draft]); ChatbotSSE::text($answer);
            ChatbotSSE::emit('answer', ['answer' => $answer, 'links' => []]);
        }
    } else {
        ChatbotSSE::emit('status', ['message' => $route['kind'] === 'query' ? 'Đang tra cứu dữ liệu trong phạm vi của bạn…' : 'Đang đối chiếu hướng dẫn ứng dụng…']);
        if (isset($route['answer'])) $answerData = $route;
        elseif ($route['kind'] === 'query') {
            $filters = $route['filters'] ?? [];
            if (isset($route['query'])) $filters['query'] = $route['query'];
            $answerData = ChatbotQueries::answer($pdo, $user, $text, $filters);
            if (!$answerData) $answerData = ['answer' => 'Chưa hỗ trợ truy vấn này. Bạn có thể hỏi về bài tập, bài nộp, tiến độ hoặc lớp trong phạm vi của mình.'];
        } else $answerData = AIAssistant::help($user, $text);
        $links = ChatbotHttp::links($answerData['links'] ?? []);
        ChatbotHttp::guard($user, $scope); $store->heartbeat($token);
        $store->finish($token, $answerData['answer'], $links, null, null, !empty($answerData['needs_clarification']) ? $text : null);
        ChatbotSSE::text($answerData['answer']);
        ChatbotSSE::emit('answer', ['answer' => $answerData['answer'], 'links' => $links, 'choices' => $answerData['choices'] ?? []]);
    }
    ChatbotSSE::emit('done', ['ok' => true]);
} catch (Throwable $e) {
    if ($store && $token !== '') { try { $store->fail($token); } catch (Throwable $ignored) {} }
    $error = ChatbotHttp::error($e);
    if ($streaming) { ChatbotSSE::emit('error', $error); ChatbotSSE::emit('done', ['ok' => false]); }
    else ChatbotHttp::json($error, $error['status']);
}
