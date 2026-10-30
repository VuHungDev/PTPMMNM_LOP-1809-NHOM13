<?php
// backend/lib/AIHelper.php — Gọi AI bóc tách đề bài thô thành khung bài tập có cấu trúc.
// Không có key → dùng bộ bóc tách cục bộ (heuristic), đủ để trải nghiệm toàn luồng.

class AIHelper
{
    public static function config(): array
    {
        static $cfg = null;
        if ($cfg === null) {
            $cfg = require __DIR__ . '/../config/ai.php';
        }
        return $cfg;
    }

    public static function hasKey(): bool
    {
        return trim((string)(self::config()['api_key'] ?? '')) !== '';
    }

    /**
     * Chat tự do với Gemini: trả về TEXT thuần (không ép JSON).
     * $system là prompt hệ thống mô tả vai trò + dữ liệu ngữ cảnh.
     */
    public static function chat(string $system, string $userMsg, int $maxTokens = 1024): string
    {
        $cfg = self::config();
        if (!self::hasKey()) {
            throw new Exception('no-key');
        }
        $model = $cfg['model'] ?? 'gemini-3.8-flash';
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . urlencode($model) . ':generateContent?key=' . urlencode($cfg['api_key']);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => (int)($cfg['timeout'] ?? 60),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'system_instruction' => ['parts' => [['text' => $system]]],
                'contents' => [['parts' => [['text' => $userMsg]]]],
                'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => $maxTokens],
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $resp = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($resp === false || $http < 200 || $http >= 300) {
            $detail = '';
            $j = json_decode((string)$resp, true);
            if (!empty($j['error']['message'])) $detail = ' — ' . $j['error']['message'];
            throw new Exception(($curlErr !== '' ? $curlErr : ('HTTP ' . $http)) . $detail);
        }
        $json = json_decode($resp, true);
        $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if (trim($text) === '') {
            throw new Exception('AI không trả lời, hãy thử lại.');
        }
        return trim($text);
    }

    /** Bóc tách đề thô → mảng cấu trúc. Trả về ['data' => [...], 'source' => 'ai'|'local'] */
    public static function parseProblem(string $rawText): array
    {
        $rawText = trim($rawText);
        if ($rawText === '') {
            throw new Exception('Hãy dán đề bài vào trước.');
        }
        if (mb_strlen($rawText) > 12000) {
            throw new Exception('Đề quá dài (tối đa ~12000 ký tự). Hãy rút gọn đề rồi thử lại.');
        }
        if (self::hasKey()) {
            try {
                return ['data' => self::parseViaApi($rawText), 'source' => 'ai'];
            } catch (Exception $e) {
                // Rớt mạng / key sai → vẫn trả kết quả cục bộ để không chặn người dùng
                $data = self::parseLocal($rawText);
                $data['_note'] = 'AI lỗi (' . $e->getMessage() . ') — dùng kết quả bóc tách tự động, bạn kiểm tra lại nhé.';
                return ['data' => $data, 'source' => 'local'];
            }
        }
        return ['data' => self::parseLocal($rawText), 'source' => 'local'];
    }

    /** Gọi model: Gemini (mặc định) hoặc OpenAI-tương thích, ép trả JSON. */
    private static function parseViaApi(string $rawText): array
    {
        $cfg = self::config();
        $schema = '{"title":string,"topic":string,"difficulty":"Easy|Medium|Hard",'
            . '"description":string,"input_desc":string,"output_desc":string,'
            . '"sample_input":string,"sample_output":string,"time_limit_ms":number,'
            . '"test_cases":[{"stdin":string,"expected":string,"is_public":bool}]}';
        $prompt = 'Bạn là trợ lý soạn đề lập trình cho giảng viên. Đọc đề bài tiếng Việt sau và trích ra đúng JSON theo schema, không thêm chữ nào khác. '
            . 'topic chọn 1 trong: Cơ bản, Rẽ nhánh, Vòng lặp, Số học, Mảng, Xử lý chuỗi, Tìm kiếm, Quy hoạch động. '
            . 'Tạo 2-4 test_cases: ca 1 công khai trùng ví dụ mẫu, còn lại is_public=false gồm test thường và biên. '
            . 'Schema: ' . $schema . "\n\nĐỀ BÀI:\n" . $rawText;

        if (($cfg['provider'] ?? 'gemini') === 'gemini') {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
                . urlencode($cfg['model']) . ':generateContent?key=' . urlencode($cfg['api_key']);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => (int)($cfg['timeout'] ?? 60),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode([
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['temperature' => 0.2, 'responseMimeType' => 'application/json'],
                ], JSON_UNESCAPED_UNICODE),
            ]);
            $resp = curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);
            if ($resp === false || $http < 200 || $http >= 300) {
                $detail = '';
                $j = json_decode((string)$resp, true);
                if (!empty($j['error']['message'])) $detail = ' — ' . $j['error']['message'];
                throw new Exception(($curlErr !== '' ? $curlErr : ('HTTP ' . $http)) . $detail);
            }
            $json = json_decode($resp, true);
            $content = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $data = json_decode($content, true);
            if (!is_array($data) || empty($data['title']) || empty($data['description'])) {
                throw new Exception('AI trả về thiếu trường, hãy thử lại.');
            }
            return self::normalize($data);
        }

        // Fallback: cổng tương thích OpenAI
        $payload = [
            'model' => $cfg['model'],
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => 'Bạn là trợ lý soạn đề lập trình cho giảng viên. Đọc đề bài tiếng Việt và trích ra đúng JSON theo schema, không thêm chữ nào khác. topic chọn 1 trong: Cơ bản, Rẽ nhánh, Vòng lặp, Số học, Mảng, Xử lý chuỗi, Tìm kiếm, Quy hoạch động. Tạo 2-4 test_cases: ca 1 công khai trùng ví dụ mẫu, còn lại is_public=false gồm test thường và biên. Schema: ' . $schema],
                ['role' => 'user', 'content' => $rawText],
            ],
        ];
        $ch = curl_init(rtrim($cfg['base_url'] ?? 'https://api.openai.com/v1', '/') . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => (int)($cfg['timeout'] ?? 60),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $cfg['api_key'],
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $resp = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($resp === false || $http < 200 || $http >= 300) {
            throw new Exception($curlErr !== '' ? $curlErr : ('HTTP ' . $http));
        }
        $json = json_decode($resp, true);
        $content = $json['choices'][0]['message']['content'] ?? '';
        $data = json_decode($content, true);
        if (!is_array($data) || empty($data['title']) || empty($data['description'])) {
            throw new Exception('AI trả về thiếu trường, hãy thử lại.');
        }
        return self::normalize($data);
    }

    /** Bóc tách cục bộ: đủ dùng khi chưa có key. */
    public static function parseLocal(string $rawText): array
    {
        $lines = preg_split('/\r?\n/', $rawText);
        // Dòng đầu ngắn → tiêu đề
        $title = 'Bài tập mới';
        foreach ($lines as $ln) {
            $t = trim($ln, " \t#*");
            if ($t !== '' && mb_strlen($t) <= 80) {
                $title = $t;
                break;
            }
        }
        // Tìm khối Input/Output mẫu theo từ khóa thường gặp
        $sampleIn = self::extractBlock($rawText, ['ví dụ.*input', 'input.*mẫu', 'input:', 'đầu vào.*ví dụ']);
        $sampleOut = self::extractBlock($rawText, ['ví dụ.*output', 'output.*mẫu', 'output:', 'đầu ra.*ví dụ']);
        $inputDesc = self::extractBlock($rawText, ['đầu vào', 'input']) ?: '';
        $outputDesc = self::extractBlock($rawText, ['đầu ra', 'output']) ?: '';
        if ($inputDesc === $sampleIn) $inputDesc = '';
        if ($outputDesc === $sampleOut) $outputDesc = '';

        $data = [
            'title' => $title,
            'topic' => self::guessTopic($rawText),
            'difficulty' => 'Easy',
            'description' => trim($rawText),
            'input_desc' => $inputDesc,
            'output_desc' => $outputDesc,
            'sample_input' => $sampleIn,
            'sample_output' => $sampleOut,
            'time_limit_ms' => 1000,
            'test_cases' => [],
        ];
        if ($sampleIn !== '' || $sampleOut !== '') {
            $data['test_cases'][] = ['stdin' => $sampleIn, 'expected' => $sampleOut, 'is_public' => true];
        }
        $data['test_cases'][] = ['stdin' => '', 'expected' => '', 'is_public' => false];
        return self::normalize($data);
    }

    private static function extractBlock(string $text, array $patterns): string
    {
        $lines = preg_split('/\r?\n/', $text);
        foreach ($patterns as $p) {
            foreach ($lines as $i => $ln) {
                if (preg_match('/' . $p . '/iu', $ln)) {
                    // Lấy nội dung sau dấu : cùng dòng + tối đa 3 dòng tiếp theo không rỗng
                    $buf = [];
                    $after = trim(preg_replace('/^[^:：]*[:：]/u', '', $ln));
                    if ($after !== '' && !preg_match('/' . $p . '/iu', $after)) $buf[] = $after;
                    for ($j = $i + 1; $j < min($i + 4, count($lines)); $j++) {
                        $t = trim($lines[$j]);
                        if ($t === '') break;
                        if (mb_strlen($t) > 200) break;
                        $buf[] = $t;
                    }
                    if ($buf) return implode("\n", $buf);
                }
            }
        }
        return '';
    }

    private static function guessTopic(string $text): string
    {
        $t = mb_strtolower($text);
        if (preg_match('/u[ée] *qui|quy ho[ạa]ch|dp\b|dynamic/', $t)) return 'Quy hoạch động';
        if (str_contains($t, 'chuỗi') || str_contains($t, 'string') || str_contains($t, 'xâu')) return 'Xử lý chuỗi';
        if (str_contains($t, 'mảng') || str_contains($t, 'array')) return 'Mảng';
        if (str_contains($t, 'vòng lặp') || str_contains($t, 'for ') || str_contains($t, 'while')) return 'Vòng lặp';
        if (str_contains($t, 'nếu') || str_contains($t, 'if ')) return 'Rẽ nhánh';
        if (preg_match('/số nguyên tố|ước|fibonacci|giai thừa|modulo|%/', $t)) return 'Số học';
        if (preg_match('/tìm kiếm|binary search|tìm /', $t)) return 'Tìm kiếm';
        return 'Cơ bản';
    }

    /** Chuẩn hóa dữ liệu từ AI/local về đúng kiểu form cần. */
    public static function normalize(array $d): array
    {
        $diff = $d['difficulty'] ?? 'Easy';
        if (!in_array($diff, ['Easy', 'Medium', 'Hard'], true)) $diff = 'Easy';
        $topics = ['Cơ bản', 'Rẽ nhánh', 'Vòng lặp', 'Số học', 'Mảng', 'Xử lý chuỗi', 'Tìm kiếm', 'Quy hoạch động'];
        $topic = $d['topic'] ?? 'Cơ bản';
        if (!in_array($topic, $topics, true)) $topic = 'Cơ bản';
        $cases = [];
        foreach (($d['test_cases'] ?? []) as $c) {
            $cases[] = [
                'stdin' => (string)($c['stdin'] ?? $c['stdin_data'] ?? ''),
                'expected' => (string)($c['expected'] ?? $c['expected_output'] ?? ''),
                'is_public' => !empty($c['is_public']),
            ];
        }
        if (!$cases) $cases[] = ['stdin' => '', 'expected' => '', 'is_public' => true];
        return [
            'title' => trim((string)($d['title'] ?? 'Bài tập mới')),
            'topic' => $topic,
            'difficulty' => $diff,
            'description' => trim((string)($d['description'] ?? '')),
            'input_desc' => trim((string)($d['input_desc'] ?? '')),
            'output_desc' => trim((string)($d['output_desc'] ?? '')),
            'sample_input' => trim((string)($d['sample_input'] ?? '')),
            'sample_output' => trim((string)($d['sample_output'] ?? '')),
            'time_limit_ms' => max(100, (int)($d['time_limit_ms'] ?? 1000)),
            'test_cases' => array_slice($cases, 0, 10),
        ];
    }

    /** Tạo slug từ tiêu đề (dùng chung cho API tạo bài). */
    public static function slugify(string $title): string
    {
        $t = mb_strtolower($title, 'UTF-8');
        $map = ['áàảãạăắằẳẵặâấầẩẫậ' => 'a', 'éèẻẽẹêếềểễệ' => 'e', 'íìỉĩị' => 'i',
                'óòỏõọôốồổỗộơớờởỡợ' => 'o', 'úùủũụưứừửữự' => 'u', 'ýỳỷỹỵ' => 'y'];
        foreach ($map as $chars => $rep) {
            $t = preg_replace('/[' . $chars . ']/u', $rep, $t);
        }
        $t = str_replace('đ', 'd', $t);
        $t = preg_replace('/[^a-z0-9]+/', '-', $t);
        $slug = substr(trim($t, '-'), 0, 60);
        if (strlen($slug) < 3) $slug = 'bai-' . substr(md5($title . microtime()), 0, 6);
        return $slug;
    }
}
