<?php
/** Read-only standard catalog. Never require/execute the destructive seed scripts. */
function reference_seed_array(string $path): array
{
    $source = file_get_contents($path);
    if (!is_string($source)) throw new RuntimeException('Cannot read the standard exercise catalog.');
    $tokens = token_get_all($source);
    $collect = false;
    $expression = '';
    $depth = 0;
    $foundAssignment = false;
    foreach ($tokens as $token) {
        $text = is_array($token) ? $token[1] : $token;
        if (!$collect) {
            if (is_array($token) && $token[0] === T_VARIABLE && $text === '$problemsData') {
                $foundAssignment = true;
            } elseif ($foundAssignment && $text === '[') {
                $collect = true;
                $depth = 1;
                $expression = '[';
            } elseif ($foundAssignment && !in_array($text, ['=', ' ', "\n", "\r\n"], true)
                && !(is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
                throw new RuntimeException('Catalog assignment changed; review the read-only extractor.');
            }
            continue;
        }
        if (!is_array($token) && $text === '[') $depth++;
        if (!is_array($token) && $text === ']') $depth--;
        // Exhaustive allowlist: expressions such as print/exit/backticks/interpolation
        // must never become executable while reading a data-only seed array.
        if (is_array($token)) {
            if (!in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER, T_DOUBLE_ARROW, T_STRING], true)) {
                throw new RuntimeException('Unexpected executable token in seed catalog.');
            }
            if ($token[0] === T_STRING && !in_array(strtolower($text), ['str_repeat', 'trim', 'true', 'false', 'null'], true)) {
                throw new RuntimeException('Unexpected function or constant in seed catalog: ' . $text);
            }
        } elseif (!in_array($text, ['[', ']', '(', ')', ',', '.', '+', '-', '*', '/'], true)) {
            throw new RuntimeException('Unexpected operator in seed catalog.');
        }
        $expression .= $text;
        if ($depth === 0) {
            $significant = array_values(array_filter(token_get_all('<?php ' . $expression), static function ($item): bool {
                return !is_array($item) || !in_array($item[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
            }));
            foreach ($significant as $index => $item) {
                if ($item === '(') {
                    $previous = $significant[$index - 1] ?? null;
                    if (!is_array($previous) || $previous[0] !== T_STRING || !in_array(strtolower($previous[1]), ['trim', 'str_repeat'], true)) {
                        throw new RuntimeException('Only direct approved helper calls may use parentheses in the seed catalog.');
                    }
                }
            }
            $result = eval('return ' . $expression . ';');
            if (!is_array($result)) throw new RuntimeException('Catalog is not an array.');
            return $result;
        }
    }
    throw new RuntimeException('Standard catalog array not found.');
}

function reference_standard_catalog(): array
{
    $root = dirname(__DIR__);
    $catalog = [];
    foreach (reference_seed_array($root . '/database/seeds/seed_10_problems.php') as $p) {
        $catalog[$p['slug']] = [
            'slug' => $p['slug'], 'title' => $p['title'], 'language' => 'python',
            'description' => $p['desc'], 'input_desc' => $p['in_desc'], 'output_desc' => $p['out_desc'],
            'time_limit_ms' => $p['time'], 'memory_limit_kb' => 65536, 'cases' => $p['cases'],
        ];
    }
    foreach (reference_seed_array($root . '/database/seeds/seed_60_problems.php') as $p) {
        [$slug, $title, $topic, $difficulty, $language, $description, $input, $output, $time, $cases] = $p;
        if (isset($catalog[$slug])) throw new RuntimeException('Duplicate standard slug: ' . $slug);
        $catalog[$slug] = [
            'slug' => $slug, 'title' => $title, 'language' => $language,
            'description' => $description, 'input_desc' => $input, 'output_desc' => $output,
            'time_limit_ms' => $time, 'memory_limit_kb' => 65536, 'cases' => $cases,
        ];
    }
    if (count($catalog) !== 60) throw new RuntimeException('Expected exactly 60 standard exercises.');
    return $catalog;
}

/** Statement-correct cases, separate from unchanged legacy judge expectations. */
function reference_validation_cases(array $exercise): array
{
    $cases = [];
    foreach ($exercise['cases'] as $index => $case) {
        [$stdin, $expected] = $case;
        $legacy = $expected;
        $note = null;
        if ($exercise['slug'] === 'c-mult-table') {
            $n = (int)$stdin;
            $lines = [];
            for ($i = 1; $i <= $n; $i++) $lines[] = "$n x $i = " . ($n * $i);
            $expected = implode("\n", $lines);
            if ($expected !== $legacy) $note = 'Legacy expected output is truncated; the statement requires all N rows.';
        } elseif ($exercise['slug'] === 'js-palindrome-str' && $stdin === 'AbA') {
            $expected = 'YES';
            $note = 'AbA is a case-sensitive palindrome; the legacy expected NO contradicts the statement.';
        } elseif (in_array($exercise['slug'], ['cpp-two-sum-idx', 'js-two-sum'], true)) {
            $tokens = preg_split('/\s+/', trim($stdin));
            $n = (int)$tokens[0]; $sum = (int)$tokens[1]; $values = array_slice($tokens, 2, $n);
            $seen = []; $pairs = []; $first = null;
            foreach ($values as $j => $value) {
                $value = (int)$value;
                if (isset($seen[$sum - $value])) {
                    foreach ($seen[$sum - $value] as $i) {
                        $pairs[] = [$i, $j];
                        if ($first === null) $first = "$i $j";
                    }
                }
                $seen[$value][] = $j;
            }
            if (count($pairs) > 1) {
                $expected = $first;
                $note = 'Legacy input violates the unique-pair promise; the reference returns the first j, then earliest i. Exact-output judging may reject another valid pair.';
            }
        }
        $cases[] = ['stdin' => $stdin, 'expected_stdout' => $expected, 'legacy_expected_stdout' => $legacy,
            'origin' => 'seed_case_' . ($index + 1), 'dataset_issue' => $note];
    }
    $extras = [
        'factorial-n' => [['20', '2432902008176640000']],
        'fibonacci-nth' => [['70', '190392490709135']],
        'prime-check' => [['1000000000', 'FALSE'], ['999999937', 'TRUE']],
        'c-mult-table' => [['4', "4 x 1 = 4\n4 x 2 = 8\n4 x 3 = 12\n4 x 4 = 16"]],
        'c-upper-lower' => [["École", "éCOLE"], ["Tiếng Việt", "tIẾNG vIỆT"]],
        'cpp-string-len' => [["é a", '1'], ["lập trình", '5']],
        'cpp-gcd-lcm' => [['999999937 1000000000', "1\n999999937000000000"]],
        'java-palindrome-num' => [['1000000000000000000', 'NO']],
        'cpp-two-sum-idx' => [["5 10\n1 2 3 7 20", '2 3'], ["6 0\n-1 1 2 3 4 5", '0 1']],
        'js-two-sum' => [["6 7\n1 10 20 30 40 6", '0 5']],
        'php-sum-range' => [['-1000000000 1000000000', '0'], ['-1000000000 -999999999', '-1999999999']],
        'php-strrev' => [[" a ", " a "], ['é', 'é'], ['Việt', 'tệiV']],
        'js-palindrome-str' => [['Aa', 'NO'], [' A ', 'YES'], [' a', 'NO']],
    ];
    foreach ($extras[$exercise['slug']] ?? [] as [$stdin, $expected]) {
        $cases[] = ['stdin' => $stdin, 'expected_stdout' => $expected, 'legacy_expected_stdout' => null,
            'origin' => 'extra_boundary', 'dataset_issue' => null];
    }
    return $cases;
}
