<?php
/** Real provider SSE transport. No request text, credentials or provider body in errors/logs. */
require_once __DIR__ . '/AIHelper.php';
final class AIStream
{
    public static function generate(string $system, string $text, callable $delta, callable $tick, int $maxTokens = 8192): string
    {
        $cfg = AIHelper::config();
        if (!AIHelper::hasKey()) throw new AIRequestException('Chưa cấu hình khóa AI trên máy chủ.', 'ai_config', 503);
        if (!function_exists('curl_multi_init')) throw new AIRequestException('PHP cần bật tiện ích cURL.', 'ai_config', 503);
        $gemini = $cfg['provider'] === 'gemini';
        $headers = ['Content-Type: application/json', 'Accept: text/event-stream'];
        if ($gemini) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($cfg['model']) . ':streamGenerateContent?alt=sse';
            $headers[] = 'x-goog-api-key: ' . $cfg['api_key'];
            $payload = ['system_instruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $text]]]],
                'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => $maxTokens,
                    'responseMimeType' => 'application/json', 'responseSchema' => ChatbotDraft::schema()]];
        } else {
            $url = rtrim($cfg['base_url'], '/') . '/chat/completions';
            $headers[] = 'Authorization: Bearer ' . $cfg['api_key'];
            $payload = ['model' => $cfg['model'], 'temperature' => 0.2, 'max_tokens' => $maxTokens, 'stream' => true,
                'response_format' => ['type' => 'json_object'],
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $text]]];
        }
        $timeout = (int)$cfg['timeout'];
        if (function_exists('set_time_limit')) set_time_limit($timeout === 0 ? 0 : max(30, $timeout + 20));
        $buffer = ''; $output = ''; $finish = ''; $done = false; $failure = null;
        $ch = curl_init($url);
        $consume = static function (string $event) use (&$output, &$finish, &$done, $gemini, $delta): void {
            $data = [];
            foreach (explode("\n", $event) as $line) {
                if (str_starts_with($line, 'data:')) $data[] = ltrim(substr($line, 5), ' ');
            }
            if (!$data) return;
            $json = implode("\n", $data);
            if ($json === '[DONE]') { $done = true; return; }
            try { $body = json_decode($json, true, 64, JSON_THROW_ON_ERROR); }
            catch (JsonException $e) { throw new AIRequestException('Luồng AI trả dữ liệu không hợp lệ.', 'ai_stream', 502); }
            if (!is_array($body) || isset($body['error'])) throw new AIRequestException('Dịch vụ AI báo lỗi trong lúc trả kết quả.', 'ai_provider', 502);
            $piece = '';
            if ($gemini) {
                $candidate = $body['candidates'][0] ?? [];
                foreach ($candidate['content']['parts'] ?? [] as $part) {
                    if (empty($part['thought']) && is_string($part['text'] ?? null)) $piece .= $part['text'];
                }
                if (isset($candidate['finishReason'])) $finish = (string)$candidate['finishReason'];
                if (isset($body['promptFeedback']['blockReason'])) $finish = 'BLOCKED';
            } else {
                $choice = $body['choices'][0] ?? [];
                if (is_string($choice['delta']['content'] ?? null)) $piece = $choice['delta']['content'];
                if (isset($choice['finish_reason'])) $finish = (string)$choice['finish_reason'];
            }
            if ($piece !== '') {
                if (strlen($output) + strlen($piece) > 262144) throw new AIRequestException('Kết quả AI vượt kích thước cho phép.', 'ai_size', 502);
                $output .= $piece;
                $delta($piece, $output);
            }
        };
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            CURLOPT_CONNECTTIMEOUT => $timeout > 0 ? min(10, $timeout) : 10, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $bytes) use (&$buffer, &$failure, $consume): int {
                if ((int)curl_getinfo($handle, CURLINFO_HTTP_CODE) >= 400) return strlen($bytes);
                try {
                    $buffer .= $bytes;
                    if (strlen($buffer) > 1048576) throw new AIRequestException('Luồng AI vượt kích thước cho phép.', 'ai_size', 502);
                    // SSE accepts CRLF, LF and CR. Keep a trailing CR until the next chunk.
                    $buffer = preg_replace('/\r\n|\r(?!$)/', "\n", $buffer);
                    while (($end = strpos($buffer, "\n\n")) !== false) {
                        $event = substr($buffer, 0, $end); $buffer = substr($buffer, $end + 2);
                        $consume($event);
                    }
                    return strlen($bytes);
                } catch (Throwable $e) { $failure = $e; return 0; }
            }]);
        $multi = curl_multi_init();
        curl_multi_add_handle($multi, $ch);
        $lastTick = 0.0;
        try {
            do {
                if (microtime(true) - $lastTick >= 1) { $tick(); $lastTick = microtime(true); }
                $code = curl_multi_exec($multi, $running);
                if ($failure) throw $failure;
                if ($code !== CURLM_OK) throw new AIRequestException('Không đọc được luồng AI.', 'ai_connection', 502);
                if ($running) { if (curl_multi_select($multi, 0.25) === -1) usleep(10000); }
            } while ($running);
            $info = curl_multi_info_read($multi);
            $errno = $info['result'] ?? curl_errno($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                $connected = (float)curl_getinfo($ch, CURLINFO_CONNECT_TIME) > 0;
                $message = $connected && $timeout > 0 ? 'AI chưa trả kết quả trong ' . $timeout . ' giây. Đây không phải lỗi thiếu nội dung đề.' : 'Hết thời gian thiết lập kết nối AI. Hãy kiểm tra mạng máy chủ.';
                throw new AIRequestException($message, 'ai_timeout', 504);
            }
            if ($errno !== CURLE_OK) throw new AIRequestException('Kết nối AI bị gián đoạn. Bản nháp cũ được giữ nguyên.', 'ai_connection', 502);
            if ($http < 200 || $http >= 300) {
                $hint = match ($http) {
                    400 => 'Cấu hình model/yêu cầu AI chưa hợp lệ.',
                    401, 403 => 'Khóa AI không hợp lệ hoặc chưa được cấp quyền.',
                    404 => 'Không tìm thấy model AI; hãy kiểm tra AI_MODEL.',
                    429 => 'AI đang giới hạn lượt gọi hoặc hết hạn mức.',
                    default => 'Dịch vụ AI tạm thời không khả dụng.',
                };
                throw new AIRequestException($hint . ' (HTTP ' . $http . ')', $http === 429 ? 'ai_quota' : 'ai_provider', $http === 429 ? 429 : 502);
            }
            if (trim($buffer) !== '') $consume(str_replace("\r", "\n", trim($buffer)));
            if (in_array($finish, ['MAX_TOKENS', 'length'], true)) throw new AIRequestException('AI trả lời bị cắt do giới hạn đầu ra. Hãy rút gọn đề hoặc thử lại.', 'ai_cutoff', 502);
            if ($finish !== '' && !in_array($finish, ['STOP', 'stop', 'end_turn'], true)) throw new AIRequestException('AI chưa hoàn thành bản nháp hoặc từ chối nội dung này.', 'ai_incomplete', 502);
            if ((!$done && $finish === '') || trim($output) === '') throw new AIRequestException('Luồng AI kết thúc trước khi có kết quả hoàn chỉnh.', 'ai_stream', 502);
            $tick();
            return trim($output);
        } finally {
            curl_multi_remove_handle($multi, $ch); curl_close($ch); curl_multi_close($multi);
        }
    }
}
