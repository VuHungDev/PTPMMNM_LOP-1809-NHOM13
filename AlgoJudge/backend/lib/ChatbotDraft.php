<?php
require_once __DIR__ . '/AIHelper.php';
/** Canonical form shared by streaming, revision updates, validation and persistence. */
final class ChatbotDraft
{
    public const TOPICS = ['Cơ bản','Rẽ nhánh','Vòng lặp','Số học','Mảng','Xử lý chuỗi','Tìm kiếm','Quy hoạch động'];
    public const LANGUAGES = ['c','cpp','java','python','php','js'];
    private const TEXT_FIELDS = ['title','slug','topic','difficulty','suggested_lang','description','input_desc','output_desc','sample_input','sample_output'];
    public static function schema(): array
    {
        $schema = AIHelper::problemSchema();
        foreach (['slug','suggested_lang','clarification'] as $f) $schema['properties'][$f] = ['type' => 'STRING'];
        $schema['required'] = array_keys($schema['properties']); return $schema;
    }
    public static function prompt(): string
    {
        return AIHelper::problemPrompt() . <<<'PROMPT'

11. Thêm các trường slug (chữ thường ASCII, số, gạch ngang; 3-100 ký tự), suggested_lang (c/cpp/java/python/php/js; mặc định python) và clarification (chuỗi rỗng khi đã rõ yêu cầu).
12. Nếu đề chưa nêu mục tiêu tính toán hoặc có mâu thuẫn cốt lõi không giải quyết được, KHÔNG tự chọn mục tiêu. Điền clarification bằng câu hỏi ngắn để người dùng làm rõ; các trường còn lại có thể rỗng. Không hỏi lại chỉ vì thiếu định dạng I/O, giới hạn, mẫu hoặc test; các phần này bạn phải tự bổ sung hợp lý.
13. Nếu có bản nháp hiện tại và yêu cầu chỉnh sửa: giữ mọi trường đã duyệt không liên quan. Phải cập nhật lại ví dụ và test khi ngữ nghĩa/ràng buộc thay đổi, không để test cũ mâu thuẫn. Trả toàn bộ object; không tự ghi bài, không sinh lệnh execute/SQL.
14. Không tuyên bố đáp án đã được kiểm chứng bằng thực thi. Người dùng sẽ kiểm tra và bấm Thêm bài sau khi duyệt. Không được thay biến, token hoặc dữ liệu máy chấm bằng bản dịch.
PROMPT;
    }
    public static function normalize(array $raw, bool $generated = false): array
    {
        $organized = $generated ? ProblemDraft::organize($raw) : $raw;
        $d = [];
        foreach (self::TEXT_FIELDS as $field) {
            $value = str_starts_with($field, 'sample_') && is_string($raw[$field] ?? null)
                ? $raw[$field] : ($organized[$field] ?? $raw[$field] ?? '');
            $d[$field] = is_string($value) ? str_replace(["\r\n", "\r"], "\n", $value) : '';
            if (!str_starts_with($field, 'sample_')) $d[$field] = trim($d[$field]);
        }
        if ($generated) {
            if ($d['slug'] === '') $d['slug'] = AIHelper::slugify($d['title']);
            if ($d['suggested_lang'] === '') $d['suggested_lang'] = 'python';
            if ($d['topic'] === '') $d['topic'] = 'Cơ bản';
            if ($d['difficulty'] === '') $d['difficulty'] = 'Easy';
        }
        $d['time_limit_ms'] = filter_var($raw['time_limit_ms'] ?? 1000, FILTER_VALIDATE_INT) ?: 0;
        foreach (['assumptions','warnings'] as $list) {
            $d[$list] = [];
            foreach (is_array($raw[$list] ?? null) ? $raw[$list] : [] as $note) if (is_string($note) && trim($note) !== '') $d[$list][] = trim($note);
        }
        $d['test_cases'] = [];
        foreach (is_array($raw['test_cases'] ?? null) ? $raw['test_cases'] : [] as $c) {
            if (!is_array($c)) continue;
            $d['test_cases'][] = [
                'stdin' => is_string($c['stdin'] ?? null) ? str_replace(["\r\n", "\r"], "\n", $c['stdin']) : '',
                'expected' => is_string($c['expected'] ?? null) ? str_replace(["\r\n", "\r"], "\n", $c['expected']) : '',
                'is_public' => in_array($c['is_public'] ?? false, [true,1,'1','true'], true),
                'is_boundary' => in_array($c['is_boundary'] ?? false, [true,1,'1','true'], true),
            ];
        }
        return $d;
    }
    public static function issues(array $d): array
    {
        $issues = AIHelper::draftIssues($d, true);
        if (mb_strlen($d['title']) > 200) $issues[] = 'Tiêu đề tối đa 200 ký tự.';
        if (!preg_match('/^[a-z0-9-]{3,100}$/D', $d['slug'])) $issues[] = 'Slug cần 3–100 ký tự chữ thường, số hoặc gạch ngang.';
        if (!in_array($d['topic'], self::TOPICS, true)) $issues[] = 'Chủ đề không hợp lệ.';
        if (!in_array($d['difficulty'], ['Easy','Medium','Hard'], true)) $issues[] = 'Độ khó không hợp lệ.';
        if (!in_array($d['suggested_lang'], self::LANGUAGES, true)) $issues[] = 'Ngôn ngữ gợi ý không hợp lệ.';
        if ($d['time_limit_ms'] < 100 || $d['time_limit_ms'] > 30000) $issues[] = 'Thời gian chạy phải từ 100 đến 30000 ms.';
        foreach (['description','input_desc','output_desc','sample_input','sample_output'] as $f) if (strlen($d[$f]) > 30000) $issues[] = $f . ' vượt quá 30000 byte.';
        $noInput = AIHelper::noInput($d);
        if ($noInput && $d['sample_input'] !== '') $issues[] = 'Bài không input phải có ví dụ đầu vào rỗng.';
        if (count($d['test_cases']) > 20) $issues[] = 'Tối đa 20 test case trong bản nháp.';
        $seen = [];
        foreach ($d['test_cases'] as $i => $c) {
            $n = $i + 1;
            if (ProblemDraft::isPlaceholder($c['expected'], true) || (!$noInput && ProblemDraft::isPlaceholder($c['stdin'], true))) $issues[] = "Test $n chưa có dữ liệu input/output hoàn chỉnh.";
            if ($noInput && $c['stdin'] !== '') $issues[] = "Test $n: bài không input không được có stdin.";
            if (strlen($c['stdin']) > 30000 || strlen($c['expected']) > 30000) $issues[] = "Test $n vượt kích thước 30000 byte mỗi ô.";
            $key = hash('sha256', $c['stdin']);
            if (isset($seen[$key])) $issues[] = $seen[$key] !== $c['expected'] ? 'Các test trùng input nhưng khác output.' : 'Các test bị trùng input.';
            $seen[$key] = $c['expected'];
            if ($c['stdin'] === $d['sample_input'] && $c['expected'] !== $d['sample_output']) $issues[] = 'Output ví dụ mâu thuẫn với test cùng input.';
        }
        if (count($d['assumptions']) > 30 || count($d['warnings']) > 30) $issues[] = 'Danh sách giả định/cảnh báo quá dài.';
        return array_values(array_unique($issues));
    }
    /** Complete top-level string fields only; never exposes unparsed JSON/test internals. */
    public static function fields(string $json): array
    {
        $fields = []; $depth = 0; $length = strlen($json);
        for ($i = 0; $i < $length; $i++) {
            $c = $json[$i];
            if ($c === '{' || $c === '[') { $depth++; continue; }
            if ($c === '}' || $c === ']') { $depth--; continue; }
            if ($c !== '"') continue;
            $start = $i++; $escape = false;
            for (; $i < $length; $i++) {
                if ($escape) { $escape = false; continue; }
                if ($json[$i] === '\\') { $escape = true; continue; }
                if ($json[$i] === '"') break;
            }
            if ($i >= $length) break;
            $token = substr($json, $start, $i - $start + 1);
            if ($depth !== 1) continue;
            $j = $i + 1; while ($j < $length && ctype_space($json[$j])) $j++;
            if (($json[$j] ?? '') !== ':') continue;
            $key = json_decode($token, true); $j++;
            while ($j < $length && ctype_space($json[$j])) $j++;
            if (!in_array($key, self::TEXT_FIELDS, true) || ($json[$j] ?? '') !== '"') continue;
            $startValue = $j++; $escape = false;
            for (; $j < $length; $j++) {
                if ($escape) { $escape = false; continue; }
                if ($json[$j] === '\\') { $escape = true; continue; }
                if ($json[$j] === '"') break;
            }
            if ($j >= $length) break;
            $value = json_decode(substr($json, $startValue, $j - $startValue + 1), true);
            if (is_string($value)) $fields[$key] = $value;
            $i = $j;
        }
        return $fields;
    }
    public static function generate(string $source, ?array $current, string $instruction, callable $emit, callable $tick): array
    {
        $context = ['original_statement' => $source];
        if ($current !== null) { $context['current_draft'] = $current; $context['revision_request'] = $instruction; }
        $text = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $previous = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $emit('status', ['message' => $attempt ? 'Đang hoàn thiện các mục cần sửa…' : 'Đang biên soạn khung bài tập…']);
            $sent = [];
            $content = AIStream::generate(self::prompt(), $text, static function ($piece, $all) use ($emit, &$sent) {
                foreach (self::fields($all) as $field => $value) {
                    if (!isset($sent[$field]) || $sent[$field] !== $value) { $emit('field', ['field' => $field, 'value' => $value]); $sent[$field] = $value; }
                }
            }, $tick);
            $emit('status', ['message' => 'Đang kiểm tra các trường, ví dụ và test…']);
            try {
                $object = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/iu', '', trim($content)), true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($object) || array_is_list_compat($object)) throw new UnexpectedValueException('AI phải trả object khung bài tập.');
                if (is_string($object['clarification'] ?? null) && trim($object['clarification']) !== '') return ['clarification' => mb_substr(trim($object['clarification']), 0, 1000)];
                $previous = self::normalize($object, true);
                $issues = self::issues($previous);
                if (!$issues) return ['data' => $previous];
            } catch (JsonException | UnexpectedValueException $e) { $issues = ['AI chưa trả JSON object hợp lệ.']; }
            $text = json_encode($context + ['previous_draft' => $previous, 'validation_issues' => $issues, 'repair' => 'Trả lại toàn bộ object, tự sinh mọi mục thiếu, giữ đúng ngữ nghĩa.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        throw new ChatbotError('AI chưa hoàn thiện được khung bài. Bản nháp trước (nếu có) vẫn được giữ nguyên.', 'draft_validation', 422, $issues);
    }
    public static function envelope(array $data, string $source, ?array $current = null): array
    {
        return ['id' => $current['id'] ?? bin2hex(random_bytes(16)), 'revision' => ($current['revision'] ?? 0) + 1,
            'source' => $source, 'data' => $data, 'status' => 'review', 'issues' => self::issues($data),
            'semantic_review' => false, 'save_key' => bin2hex(random_bytes(24))];
    }
}
/** PHP 8.0 compatibility (array_is_list was added in 8.1). */
function array_is_list_compat(array $a): bool { return !$a || array_keys($a) === range(0, count($a) - 1); }
