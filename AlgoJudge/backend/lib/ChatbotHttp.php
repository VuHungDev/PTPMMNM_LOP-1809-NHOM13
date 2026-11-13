<?php
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/AIRequestException.php';
require_once __DIR__ . '/ChatbotStore.php';
require_once __DIR__ . '/ChatbotDraft.php';
require_once __DIR__ . '/AIStream.php';
require_once __DIR__ . '/AuditLog.php';

final class ChatbotHttp
{
    public static function body(): array
    {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 262144) throw new ChatbotError('Nội dung gửi lên vượt 256 KB.', 'request_size', 413);
        $raw = file_get_contents('php://input', false, null, 0, 262145);
        if (strlen($raw) > 262144) throw new ChatbotError('Nội dung gửi lên vượt 256 KB.', 'request_size', 413);
        try { $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new ChatbotError('Request JSON không hợp lệ.'); }
        if (!is_array($body) || !str_starts_with(ltrim($raw), '{')) throw new ChatbotError('Request phải là JSON object.');
        if (isset($body['confirm_token']) || isset($body['action_token'])) throw new ChatbotError('Các thao tác xác nhận chatbot cũ đã được ngừng. Hãy dùng màn hình quản lý tương ứng.', 'retired_action', 410);
        return $body;
    }
    public static function store(array $user): ChatbotStore
    {
        global $pdo;
        if (empty($_SESSION['chat_scope'])) $_SESSION['chat_scope'] = bin2hex(random_bytes(16));
        return new ChatbotStore($pdo, $user, $_SESSION['chat_scope']);
    }
    public static function requireStaff(array $user): void
    {
        if (!in_array($user['role'], ['teacher','admin'], true)) throw new ChatbotError('Chức năng tạo bản nháp chỉ dành cho giáo viên và admin.', 'forbidden', 403);
    }
    public static function error(Throwable $e): array
    {
        if ($e instanceof ChatbotError) return ['ok' => false, 'error' => $e->getMessage(), 'code' => $e->reason, 'status' => $e->status, 'fields' => $e->fields];
        if ($e instanceof AIRequestException) return ['ok' => false, 'error' => $e->getMessage(), 'code' => $e->reason(), 'status' => $e->httpStatus()];
        // SQL/provider exception details are not safe for a browser.
        return ['ok' => false, 'error' => 'Không xử lý được yêu cầu. Dữ liệu đang sửa được giữ nguyên; hãy thử lại hoặc liên hệ quản trị viên.', 'code' => 'server_error', 'status' => 500];
    }
    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status); header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    public static function links(array $links): array
    {
        $safe = [];
        foreach ($links as $link) {
            $path = $link['url'] ?? '';
            if (!is_string($path) || !preg_match('~^/frontend/[a-zA-Z0-9_/?=&.%-]+$~D', $path) || str_contains($path, '..')) continue;
            $safe[] = ['label' => (string)($link['label'] ?? 'Mở trang'), 'url' => url($path)];
        }
        return $safe;
    }
    /** Configure before the first SSE byte: PHP checks use_cookies BEFORE start options. */
    public static function releaseSession(): void
    {
        session_write_close();
        ini_set('session.use_cookies', '0');
        session_cache_limiter('');
    }
    /** Re-open read-only, never hold the session lock while waiting. Fail closed on read errors. */
    public static function guard(array $user, string $scope): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (!session_start(['read_and_close' => true])) throw new ChatbotError('Không xác thực lại được phiên đăng nhập. Hãy đăng nhập lại.', 'auth_expired', 401);
        }
        $current = $_SESSION['user'] ?? [];
        if ((int)($current['id'] ?? 0) !== (int)$user['id'] || ($current['auth_version'] ?? null) !== $user['auth_version'] || ($_SESSION['chat_scope'] ?? '') !== $scope) {
            throw new ChatbotError('Phiên đăng nhập đã kết thúc. Hãy đăng nhập lại.', 'auth_expired', 401);
        }
        global $pdo;
        $q = $pdo->prepare('SELECT role,is_active,auth_version FROM users WHERE id=?'); $q->execute([$user['id']]); $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || !(int)$row['is_active'] || $row['role'] !== $user['role'] || (int)$row['auth_version'] !== (int)$user['auth_version']) throw new ChatbotError('Quyền hoặc phiên đăng nhập đã thay đổi. Hãy đăng nhập lại.', 'auth_expired', 401);
    }
}

final class ChatbotSSE
{
    public static function start(): void
    {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, private, no-transform');
        header('X-Accel-Buffering: no'); header('X-Content-Type-Options: nosniff');
        ini_set('zlib.output_compression', '0'); ini_set('output_buffering', '0');
        while (ob_get_level() > 0) ob_end_flush();
        // Disable PHP's deadline for the orchestrated stream (provider honors a positive AI_TIMEOUT).
        if (function_exists('set_time_limit')) set_time_limit(0);
        echo ':' . str_repeat(' ', 2048) . "\n\n"; flush();
    }
    public static function emit(string $type, array $data): void
    {
        echo 'event: ' . $type . "\n" . 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n\n"; flush();
    }
    public static function ping(): void { echo ": heartbeat\n\n"; flush(); }
    public static function text(string $answer): void
    {
        // Local read-only answers stream directly, with no fake network/typewriter delay.
        foreach (preg_split('/(?<=\n)/u', $answer) as $part) if ($part !== '') self::emit('delta', ['text' => $part]);
    }
}
