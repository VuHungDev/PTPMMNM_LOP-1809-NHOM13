<?php
/** Pure section cleanup; never guesses algorithms, sample answers, or input constraints. */
final class ProblemDraft
{
    public static function cleanProse(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Convert block markup before stripping tags, so pasted headings stay separate.
        $text = preg_replace('/<\s*br\b[^>]*>|<\s*\/(?:p|div|h[1-6]|li|tr|pre|section)\s*>/iu', "\n", $text);
        $text = preg_replace('/<\s*(?:p|div|h[1-6]|li|tr|pre|section)\b[^>]*>/iu', "\n", $text);
        $text = preg_replace('/<\s*\/?(?:td|th)\b[^>]*>/iu', ' ', $text);
        $text = preg_replace('/<\s*\/?(?:strong|b|em|i|code|span|ul|ol|table|thead|tbody)\b[^>]*>/iu', '', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text);
        return trim(preg_replace('/\n[\h]*\n(?:[\h]*\n)+/u', "\n\n", $text));
    }

    /** Empty prose like "theo đề bài" is not an input/output specification. */
    public static function isPlaceholder(string $text, bool $data = false): bool
    {
        $text = trim(mb_strtolower($text));
        // Machine data may legitimately be "#", "*", ".", "-" or "NULL".
        // Remove Markdown decoration only from prose, never from sample/test tokens.
        if (!$data) $text = trim($text, " \t\n\r\0\x0B`*_#.:;：");
        if ($text === '' || preg_match('/^(?:…+|\.{2,}|todo|tbd)$/iu', $text)) return true;
        if (preg_match('/^(?:<|\[)(?:input|output|stdin|expected|ví dụ|đầu vào|đầu ra)(?:>|\])$/iu', $text)) return true;
        if ($data) return false;
        if (preg_match('/^(?:[-–—]+|n\/?a|null|undefined|chưa có|không rõ)$/iu', $text)) return true;
        return (bool)preg_match('/^(?:string|text|theo (?:đề bài|mô tả|yêu cầu)|như (?:đề bài|trên)|xem (?:đề bài|mô tả)|kết quả (?:tương ứng|mong đợi|đầu ra)|dữ liệu (?:đầu vào|đầu ra)|mô tả(?: (?:đề bài|đầu vào|đầu ra))?|đầu vào|đầu ra|input|output)$/iu', $text);
    }

    /** Split prose and samples, including Markdown/HTML headings and inline labels. */
    public static function sections(string $text, string $initial = 'description'): array
    {
        $result = ['title' => '', 'description' => '', 'input_desc' => '', 'output_desc' => '', 'sample_input' => '', 'sample_output' => ''];
        $buffers = array_fill_keys(array_keys($result), []);
        $text = self::cleanProse($text);
        // Consume the WHOLE label: splitting whitespace alone corrupts "Sample Input"
        // and leaves Markdown list prefixes behind. Strip decoration only from headings.
        $labels = '(?:sample\h+(?:input|output)(?:\h*#?\d+)?|example\h+(?:input|output)|(?:input|output)\h*(?:mẫu|ví dụ)|(?:ví dụ|mẫu)\h*(?:input|output|đầu vào|đầu ra)|đầu (?:vào|ra)(?:\h*(?:mẫu|ví dụ))?|dữ liệu (?:vào|ra)|kết quả|input(?:\h+(?:format|specification))?|output(?:\h+(?:format|specification))?)';
        $inlineLabels = '/(?:^\h*(?:#{1,6}\h*|[-+•]\h+|\d+[.)]\h+)?|\h+)((?:\*{1,2}|__)?' . $labels . '(?:\*{1,2}|__)?\h*[:：](?:\*{1,2}|__)?)/iu';
        $current = $initial;
        $examples = false;
        $skipExample = false;
        $fence = '';
        $discardCode = false;
        foreach (explode("\n", $text) as $sourceLine) {
            if ($fence !== '') {
                if (preg_match('/^\h*' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}\h*$/u', $sourceLine)) {
                    $fence = '';
                    $discardCode = false;
                    continue;
                }
                if ($discardCode) continue;
                // A fenced sample is literal data, even if it contains "Input:" or Markdown.
                if (str_starts_with($current, 'sample_') || $current === 'discard') {
                    if (isset($buffers[$current])) $buffers[$current][] = $sourceLine;
                    continue;
                }
            } elseif (preg_match('/^\h*(`{3,}|~{3,})\h*([^\h]*)\h*$/u', $sourceLine, $m)) {
                $fence = $m[1];
                $discardCode = !str_starts_with($current, 'sample_') && (bool)preg_match(
                    '/^(?:python\d*|py|c|cpp|c\+\+|csharp|c#|java|javascript|js|typescript|ts|php|go|rust|ruby|sh|bash)$/i', $m[2]
                );
                continue;
            }
            foreach (explode("\n", preg_replace($inlineLabels, "\n$1", $sourceLine)) as $line) {
                $heading = preg_replace('/^\s*(?:#{1,6}\h*|[-+•]\h+|\d+[.)]\h+)/u', '', trim($line));
                $heading = str_replace(['**', '__'], '', $heading);
                if (preg_match('/^(?:examples?|ví dụ)(?:\s*#?\d+)?\s*[:：]?$/iu', $heading)) {
                    $examples = true;
                    $skipExample = (bool)$buffers['sample_input'] || (bool)$buffers['sample_output'];
                    continue;
                }
                if (preg_match('/^(?:explanation|giải thích(?:\s+ví dụ)?|solution|lời giải|test\s*cases?|bộ kiểm thử)\s*[:：]?/iu', $heading)) {
                    $current = 'discard';
                    continue;
                }
                $matched = false;
                foreach ([
                    'sample_input' => '(?:sample\s+input(?:\s*#?\d+)?|example\s+input|input\s*(?:mẫu|ví dụ)|(?:ví dụ|mẫu)\s*(?:input|đầu vào)|đầu vào\s*(?:mẫu|ví dụ))',
                    'sample_output' => '(?:sample\s+output(?:\s*#?\d+)?|example\s+output|output\s*(?:mẫu|ví dụ)|(?:ví dụ|mẫu)\s*(?:output|đầu ra)|đầu ra\s*(?:mẫu|ví dụ))',
                    'input_desc' => '(?:input(?:\s+(?:format|specification))?|đầu vào|dữ liệu vào)',
                    'output_desc' => '(?:output(?:\s+(?:format|specification))?|đầu ra|dữ liệu ra|kết quả)',
                    'title' => '(?:title|tiêu đề|tên bài)',
                    'description' => '(?:description|problem(?:\s+statement)?|mô tả(?:\s+đề bài)?|đề bài|bài toán|constraints|ràng buộc)',
                ] as $key => $pattern) {
                    if (!preg_match('/^' . $pattern . '\h*(?:[:：]\h*(.*)|\h*)$/iu', $heading, $m)) continue;
                    if ($examples && in_array($key, ['input_desc', 'output_desc'], true)) {
                        $key = $key === 'input_desc' ? 'sample_input' : 'sample_output';
                    }
                    if (str_starts_with($key, 'sample_')) {
                        $examples = true;
                        if ($key === 'sample_input' && $buffers['sample_input']) $skipExample = true;
                    }
                    $current = $skipExample && str_starts_with($key, 'sample_') ? 'discard' : $key;
                    // Keep original constraints as part of the statement rather than dropping them.
                    if ($key === 'description' && preg_match('/^(?:constraints|ràng buộc)/iu', $heading)) {
                        $buffers[$current][] = 'Ràng buộc:' . (isset($m[1]) ? ' ' . $m[1] : '');
                    } elseif (isset($buffers[$current], $m[1]) && $m[1] !== '') {
                        $buffers[$current][] = $m[1];
                    }
                    $matched = true;
                    break;
                }
                if (!$matched && isset($buffers[$current])) $buffers[$current][] = $line;
            }
        }
        foreach ($buffers as $key => $lines) $result[$key] = trim(implode("\n", $lines));
        return $result;
    }

    /** Explicit fields take priority; embedded headings fill only absent/generic fields. */
    public static function organize(array $d): array
    {
        $clean = [];
        $extracted = [];
        foreach (['description', 'input_desc', 'output_desc'] as $field) {
            $value = is_scalar($d[$field] ?? null) ? (string)$d[$field] : '';
            $parts = self::sections($value, $field);
            $clean[$field] = $parts[$field];
            foreach ($parts as $target => $part) {
                if ($target !== $field && $part !== '' && empty($extracted[$target])) $extracted[$target] = $part;
            }
        }
        $clean['title'] = is_scalar($d['title'] ?? null) ? self::cleanProse((string)$d['title']) : '';
        foreach (['sample_input', 'sample_output'] as $field) {
            // Canonical sample fields are machine data, not HTML/Markdown presentation.
            $clean[$field] = is_scalar($d[$field] ?? null) ? trim(str_replace(["\r\n", "\r"], "\n", (string)$d[$field])) : '';
        }
        foreach ($clean as $field => $value) {
            if (self::isPlaceholder($value, str_starts_with($field, 'sample_')) && isset($extracted[$field])) $clean[$field] = $extracted[$field];
        }
        return $clean;
    }
}
