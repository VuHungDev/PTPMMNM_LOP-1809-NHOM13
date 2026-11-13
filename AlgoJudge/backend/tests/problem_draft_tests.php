<?php
/** Offline draft regressions: synthetic text only; no bootstrap, .env, database or transport. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/AIHelper.php';

$draftChecks = 0;
$draftScenarios = 0;
$draftFailures = 0;
function checkDraft(bool $ok, string $label): void {
    global $draftChecks;
    if (!$ok) throw new RuntimeException($label);
    $draftChecks++;
}
function draftScenario(string $label, callable $test): void {
    global $draftScenarios, $draftFailures;
    try {
        $test();
        $draftScenarios++;
        echo "PASS $label\n";
    } catch (Throwable $e) {
        $draftFailures++;
        fwrite(STDERR, "FAIL $label: " . $e->getMessage() . "\n");
    }
}
function completeDraftFixture(): array {
    return [
        'title' => 'Tổng mảng', 'topic' => 'Mảng', 'difficulty' => 'Easy',
        'description' => 'Cho n số nguyên. Tính tổng của chúng.',
        'input_desc' => "Dòng đầu chứa n (1 ≤ n ≤ 100).\nDòng sau chứa n số nguyên.",
        'output_desc' => 'In tổng các phần tử của mảng.',
        'sample_input' => "3\n1 2 3", 'sample_output' => '6',
        'test_cases' => [
            ['stdin' => "3\n1 2 3", 'expected' => '6', 'is_public' => true],
            ['stdin' => "1\n0", 'expected' => '0', 'is_public' => false, 'is_boundary' => true],
            ['stdin' => "4\n1 -2 3 4", 'expected' => '6', 'is_public' => false],
        ],
    ];
}
function checkDraftFields(array $actual, array $expected): void {
    foreach ($expected as $field => $value) {
        checkDraft(($actual[$field] ?? null) === $value, "$field must preserve exact extracted content");
    }
}

draftScenario('multiline Markdown headings keep all sample rows and isolate section bodies', function (): void {
    $raw = "Tổng nhiều dòng\r\nCho n số nguyên. In tổng của chúng.\r\n"
        . "## Input Format\r\nDòng đầu là n.\r\nTiếp theo là n số nguyên, mỗi số một dòng.\r\n"
        . "## Output Specification\r\nIn tổng trên một dòng.\r\n"
        . "### Sample Input 1\r\n```text\r\n4\r\n1\r\n2\r\n3\r\n4\r\n```\r\n"
        . "### Sample Output 1\r\n```text\r\n10\r\n```";
    $draft = AIHelper::parseLocal($raw);
    checkDraftFields($draft, [
        'title' => 'Tổng nhiều dòng',
        'input_desc' => "Dòng đầu là n.\nTiếp theo là n số nguyên, mỗi số một dòng.",
        'output_desc' => 'In tổng trên một dòng.',
        'sample_input' => "4\n1\n2\n3\n4", 'sample_output' => '10',
    ]);
    checkDraft(strpos($draft['description'], 'Sample Input') === false, 'sample headings do not leak into description');
    checkDraft(count($draft['test_cases']) === 1, 'only a genuine sample seeds a local test');
    checkDraft($draft['test_cases'][0]['is_public'] === true, 'extracted sample test is public');
    checkDraft($draft['test_cases'][0]['stdin'] === "4\n1\n2\n3\n4", 'sample test retains multiline stdin');
});

draftScenario('Vietnamese inline heading bodies are separated from dedicated examples', function (): void {
    $draft = AIHelper::parseLocal("Tổng hai số\nMô tả: Cho hai số nguyên. Tính tổng.\n"
        . "Dữ liệu vào: Hai số nguyên a và b trên một dòng.\nKết quả: In tổng a + b.\n"
        . "Đầu vào ví dụ: 2 3\nĐầu ra ví dụ: 5");
    checkDraftFields($draft, [
        'input_desc' => 'Hai số nguyên a và b trên một dòng.',
        'output_desc' => 'In tổng a + b.', 'sample_input' => '2 3', 'sample_output' => '5',
    ]);
});

draftScenario('same-line Input Output and Sample labels stay distinct during canonicalization', function (): void {
    $draft = AIHelper::normalize([
        'title' => 'Tổng hai số',
        'description' => 'Cho hai số nguyên, tính tổng. Input: Hai số nguyên a và b. '
            . 'Output: In tổng a + b. Sample Input: 2 3 Sample Output: 5',
    ]);
    checkDraftFields($draft, [
        'description' => 'Cho hai số nguyên, tính tổng.',
        'input_desc' => 'Hai số nguyên a và b.', 'output_desc' => 'In tổng a + b.',
        'sample_input' => '2 3', 'sample_output' => '5',
    ]);
    checkDraft(count($draft['test_cases']) === 1, 'same-line samples create exactly one public test');
    checkDraft($draft['test_cases'][0]['is_public'] && $draft['test_cases'][0]['expected'] === '5', 'Sample Output stays out of output prose and becomes real expected data');
});

draftScenario('normalize canonicalizes a full statement embedded only in description', function (): void {
    $fixture = completeDraftFixture();
    $raw = $fixture['description'] . "\n## Input\n" . $fixture['input_desc']
        . "\n## Output\n" . $fixture['output_desc']
        . "\n## Sample Input\n" . $fixture['sample_input']
        . "\n## Sample Output\n" . $fixture['sample_output'];
    $draft = AIHelper::normalize([
        'title' => $fixture['title'], 'description' => $raw,
        'input_desc' => '', 'output_desc' => '', 'sample_input' => '', 'sample_output' => '',
    ]);
    checkDraftFields($draft, array_intersect_key($fixture, array_flip([
        'description', 'input_desc', 'output_desc', 'sample_input', 'sample_output',
    ])));
    checkDraft(count($draft['test_cases']) === 1 && $draft['test_cases'][0]['is_public'], 'canonicalized example becomes public test');
    checkDraft(AIHelper::draftIssues($draft) === [], 'real raw statement is locally complete');
    $issues = implode('; ', AIHelper::draftIssues($draft, true));
    checkDraft(strpos($issues, 'test ẩn') !== false, 'generated contract still requires hidden tests');
    checkDraft(strpos($issues, 'Thiếu định dạng') === false && strpos($issues, 'Thiếu ví dụ') === false, 'extracted sections are not reported missing');
});

draftScenario('HTML headings, line breaks and lists become readable canonical prose and exact samples', function (): void {
    $html = '<p>Cho n số nguyên. Tính tổng của chúng.</p>'
        . '<h3>Input</h3><ul><li>Dòng đầu chứa n.</li><li>Dòng sau chứa n số nguyên.</li></ul>'
        . '<h3>Output</h3><p>In tổng a &amp; b.</p>'
        . '<h3>Sample Input</h3><pre>3<br>1 2 3</pre>'
        . '<h3>Sample Output</h3><pre>6</pre>';
    $draft = AIHelper::normalize(['title' => 'Tổng mảng', 'description' => $html]);
    checkDraftFields($draft, [
        'description' => 'Cho n số nguyên. Tính tổng của chúng.',
        'output_desc' => 'In tổng a & b.', 'sample_input' => "3\n1 2 3", 'sample_output' => '6',
    ]);
    checkDraft(strpos($draft['input_desc'], 'Dòng đầu chứa n.') !== false, 'first HTML list item retained');
    checkDraft(strpos($draft['input_desc'], 'Dòng sau chứa n số nguyên.') !== false, 'second HTML list item retained');
    checkDraft(strpos($draft['input_desc'], "\n") !== false, 'HTML list items retain a line boundary');
    checkDraft(!preg_match('/<\/?(?:p|h3|ul|li|pre|br)\b/i', implode('\n', [
        $draft['description'], $draft['input_desc'], $draft['output_desc'], $draft['sample_input'], $draft['sample_output'],
    ])), 'presentation tags do not leak into canonical fields');
});

draftScenario('Markdown list-wrapped headings are recognized without treating list prose as a sample', function (): void {
    $draft = AIHelper::parseLocal("Tổng hai số\nCho hai số nguyên, tính tổng.\n"
        . "- **Input:**\nHai số nguyên a và b trên một dòng.\n"
        . "- **Output:**\nIn tổng a + b.\n"
        . "- **Sample Input:**\n2 3\n- **Sample Output:**\n5");
    checkDraftFields($draft, [
        'input_desc' => 'Hai số nguyên a và b trên một dòng.',
        'output_desc' => 'In tổng a + b.', 'sample_input' => '2 3', 'sample_output' => '5',
    ]);
    checkDraft(strpos($draft['description'], 'Sample') === false, 'list heading sections leave description');
});

draftScenario('input and output prose without sample headings never fabricate executable examples', function (): void {
    $draft = AIHelper::parseLocal("Tổng hai số\nCho hai số nguyên, tính tổng.\n"
        . "Input\nDòng duy nhất chứa hai số nguyên a và b.\n"
        . "Output\nIn tổng hai số nguyên trên một dòng.");
    checkDraftFields($draft, [
        'input_desc' => 'Dòng duy nhất chứa hai số nguyên a và b.',
        'output_desc' => 'In tổng hai số nguyên trên một dòng.', 'sample_input' => '', 'sample_output' => '',
    ]);
    checkDraft($draft['test_cases'] === [], 'prose must not be reused as test stdin/expected');
    checkDraft(AIHelper::draftIssues($draft) !== [], 'no sample remains incomplete');
});

draftScenario('Examples context routes Input/Output to samples and isolates following explanation', function (): void {
    $draft = AIHelper::parseLocal("Tổng hai số\nCho hai số nguyên, tính tổng.\n"
        . "Input\nHai số nguyên a và b trên một dòng.\nOutput\nIn tổng a + b.\n"
        . "Examples\nInput\n2 3\nOutput\n5\nExplanation\nVì 2 + 3 = 5.");
    checkDraftFields($draft, [
        'input_desc' => 'Hai số nguyên a và b trên một dòng.',
        'output_desc' => 'In tổng a + b.', 'sample_input' => '2 3', 'sample_output' => '5',
    ]);
    checkDraft(count($draft['test_cases']) === 1, 'example produces one exact test');
    checkDraft($draft['test_cases'][0]['expected'] === '5', 'explanation is not machine output');
});

draftScenario('explanation after an explicit sample cannot become sample output', function (): void {
    $draft = AIHelper::parseLocal("Tổng hai số\nCho hai số nguyên, tính tổng.\n"
        . "Input\nHai số nguyên a và b.\nOutput\nIn tổng a + b.\n"
        . "Sample Input\n2 3\nSample Output\n5\nGiải thích\nHai số 2 và 3 có tổng là 5.");
    checkDraftFields($draft, ['sample_input' => '2 3', 'sample_output' => '5']);
    checkDraft($draft['test_cases'][0]['expected'] === '5', 'Vietnamese explanation stays out of expected output');
});

draftScenario('generic nonempty input/output placeholders are still validation failures', function (): void {
    foreach (['input_desc' => ['Theo đề bài', 'đầu vào'], 'output_desc' => ['Kết quả tương ứng', 'đầu ra']] as $field => [$placeholder, $label]) {
        $draft = completeDraftFixture();
        $draft[$field] = $placeholder;
        $issues = AIHelper::draftIssues(AIHelper::normalize($draft), true);
        checkDraft($issues !== [], "$field placeholder must not pass a nonempty-only check");
        checkDraft(mb_stripos(implode('; ', $issues), $label) !== false, "$field issue names the field that must be repaired");
    }
});

draftScenario('ellipsis samples and test outputs cannot satisfy the completion contract', function (): void {
    foreach (['...', '…'] as $placeholder) {
        $draft = completeDraftFixture();
        $draft['sample_input'] = $placeholder;
        $draft['sample_output'] = $placeholder;
        $draft['test_cases'] = [
            ['stdin' => $placeholder, 'expected' => $placeholder, 'is_public' => true],
            ['stdin' => "1\n0", 'expected' => $placeholder, 'is_public' => false, 'is_boundary' => true],
            ['stdin' => "4\n1 -2 3 4", 'expected' => $placeholder, 'is_public' => false],
        ];
        $normalized = AIHelper::normalize($draft);
        checkDraft(AIHelper::draftIssues($normalized, true) !== [], 'ellipsis samples with otherwise present hidden tests are incomplete');
        $hiddenPlaceholders = completeDraftFixture();
        $hiddenPlaceholders['test_cases'][1]['expected'] = $placeholder;
        $hiddenPlaceholders['test_cases'][2]['expected'] = $placeholder;
        checkDraft(AIHelper::draftIssues(AIHelper::normalize($hiddenPlaceholders), true) !== [], 'valid public sample cannot hide placeholder outputs in all hidden tests');
    }
    $valid = completeDraftFixture();
    $valid['input_desc'] = 'Dòng đầu chứa n. Dòng sau chứa các số a1, a2, ..., an.';
    checkDraft(AIHelper::draftIssues(AIHelper::normalize($valid), true) === [], 'ordinary mathematical ellipsis inside real prose is not a placeholder');
});

draftScenario('missing samples derive from the first valid test, skipping invalid and placeholder rows', function (): void {
    $draft = completeDraftFixture();
    unset($draft['sample_input'], $draft['sample_output']);
    $draft['test_cases'] = [
        null,
        ['stdin' => [], 'expected' => []],
        ['stdin' => '...', 'expected' => '...'],
        ['stdin' => "2\n7 8", 'expected' => ''],
        ['stdin' => "1\n0", 'expected' => '0', 'is_public' => false, 'is_boundary' => true],
        ['stdin' => "3\n1 2 3", 'expected' => '6', 'is_public' => false],
    ];
    $normalized = AIHelper::normalize($draft);
    checkDraftFields($normalized, ['sample_input' => "1\n0", 'sample_output' => '0']);
    checkDraft($normalized['test_cases'][0]['stdin'] === "1\n0" && $normalized['test_cases'][0]['expected'] === '0', 'first valid data row becomes the sample');
    checkDraft($normalized['test_cases'][0]['is_public'] === true, 'derived sample is represented by a public test');
    checkDraft(count($normalized['test_cases']) === 2, 'invalid rows are dropped and sample input is not duplicated');
    checkDraft($normalized['test_cases'][1]['stdin'] === "3\n1 2 3", 'later genuine test stays intact');
    checkDraft(!empty(AIHelper::draftIssues($normalized, true)), 'sample derivation does not fabricate missing hidden coverage');
});

draftScenario('an explicit valid sample is not overwritten by the first unrelated test', function (): void {
    $fixture = completeDraftFixture();
    $fixture['test_cases'] = array_slice($fixture['test_cases'], 1);
    $draft = AIHelper::normalize($fixture);
    checkDraftFields($draft, ['sample_input' => "3\n1 2 3", 'sample_output' => '6']);
    checkDraft($draft['test_cases'][0]['is_public'] && $draft['test_cases'][0]['stdin'] === "3\n1 2 3", 'explicit sample remains the public source of truth');
    checkDraft(AIHelper::draftIssues($draft, true) === [], 'explicit sample plus genuine hidden tests remains complete');
});

draftScenario('fenced examples preserve literal heading tokens and solution code leaves the statement', function (): void {
    $draft = AIHelper::normalize([
        'title' => 'Chuyển token',
        'description' => "Cho một chuỗi ký tự. In kết quả theo quy tắc trong đề.\n"
            . "```python\nprint(input())\n```\n"
            . "## Input\nMột dòng chứa chuỗi ký tự.\n## Output\nIn chuỗi kết quả.\n"
            . "## Sample Input\n```text\nInput:\n#\n```\n"
            . "## Sample Output\n```text\nOutput:\n*\n```",
    ]);
    checkDraftFields($draft, [
        'description' => 'Cho một chuỗi ký tự. In kết quả theo quy tắc trong đề.',
        'input_desc' => 'Một dòng chứa chuỗi ký tự.', 'output_desc' => 'In chuỗi kết quả.',
        'sample_input' => "Input:\n#", 'sample_output' => "Output:\n*",
    ]);
    checkDraft($draft['test_cases'][0]['stdin'] === "Input:\n#" && $draft['test_cases'][0]['expected'] === "Output:\n*", 'sample data stays literal, not reparsed as section headings');
});

draftScenario('literal punctuation and reserved-looking tokens remain valid machine data', function (): void {
    foreach (['#', '*', '.', '-', 'NULL', 'undefined', 'N/A', '<br>', 'a &amp; b'] as $token) {
        $draft = AIHelper::normalize([
            'title' => 'In ký tự', 'description' => 'In đúng ký tự được yêu cầu.',
            'input_desc' => 'Không có dữ liệu đầu vào.', 'output_desc' => 'In đúng token được nêu trong đề.',
            'sample_input' => '', 'sample_output' => $token,
            'test_cases' => [['stdin' => '', 'expected' => $token, 'is_public' => true]],
        ]);
        checkDraft($draft['sample_output'] === $token && $draft['test_cases'][0]['expected'] === $token, "literal $token must not be dropped as filler");
        checkDraft(AIHelper::draftIssues($draft, true) === [], "literal $token is a valid exact expected output");
    }
});

fwrite(STDOUT, "Problem draft scenarios: $draftScenarios passed, $draftFailures failed ($draftChecks checks).\n");
exit($draftFailures > 0 ? 1 : 0);
