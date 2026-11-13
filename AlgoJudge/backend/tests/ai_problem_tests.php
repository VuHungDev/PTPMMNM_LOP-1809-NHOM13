<?php
/** Offline unit regressions. No bootstrap, database, .env or provider calls. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('GEMINI_API_KEY=');
putenv('AI_PROVIDER=gemini');
unset($_ENV['GEMINI_API_KEY'], $_ENV['AI_PROVIDER']);
require_once dirname(__DIR__) . '/lib/AIHelper.php';
require_once dirname(__DIR__) . '/lib/AIAssistant.php';
$checks = 0;
function checkAI(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $checks++;
    echo "PASS $label\n";
}
$admin = ['role' => 'admin', 'id' => 1];
foreach ([
    'Tạo bài tính tổng hai số nguyên',
    'Tạo cho tôi một bài tập về mảng',
    'Giúp tôi soạn đề bài kiểm tra số nguyên tố',
    'Cho hai số nguyên a và b. Hãy tính tổng của chúng.',
    'Given two integers a and b, print their sum.',
    "Array Sum\nYou are given an array of integers. Compute the sum.",
    'Cho n số nguyên. Tìm phần tử lớn nhất.',
    'Tính tổng hai số nguyên a và b.',
    'Create a programming problem about arrays',
    'Dịch đề bài sau sang tiếng Việt: Given two integers, calculate their sum.',
    "Tổng hai số\nInput\nHai số nguyên a, b\nOutput\nTổng a+b",
    'Tạo bài về xuất dữ liệu ra màn hình',
    'Tạo bài tập kèm thêm test case',
] as $text) {
    $route = AIAssistant::route($admin, $text);
    checkAI($route['kind'] === 'draft' || !empty($route['needs_clarification']), 'route problem or ask its core goal: ' . $text);
}
checkAI(AIAssistant::route($admin, 'Một yêu cầu tùy ý')['kind'] !== 'draft', 'arbitrary text never forces composer mode');
checkAI(!empty(AIAssistant::route($admin, "Nội dung đề bài\nInput\n2 3\nOutput\n5")['needs_clarification']), 'sample labels alone require a core objective instead of inventing a problem');
checkAI(!AIAssistant::isProblemRequest('Cách tạo bài tập như thế nào?'), 'how-to stays QA');
checkAI(!AIAssistant::isProblemRequest('Làm sao để tạo bài?'), 'usage question stays QA');
checkAI(AIAssistant::route(['role' => 'student'], 'Tạo bài tính tổng')['kind'] === 'answer', 'composer cannot bypass student role');
$_SESSION = [];
$testAction = AIAssistant::route($admin, "Thêm test cho bài #3\ninput: 2 3\noutput: 5");
checkAI($testAction['kind'] === 'answer' && !isset($testAction['action']), 'old add-test command is retired even for admin');
$raw = "Sum\nGiven two integers, calculate their sum.\nInput\nTwo integers a and b.\nOutput\nTheir sum.\nSample Input\n2 3\nSample Output\n5";
$local = AIHelper::parseLocal($raw);
checkAI($local['input_desc'] === 'Two integers a and b.', 'extract input without output contamination');
checkAI($local['output_desc'] === 'Their sum.', 'extract output without sample contamination');
checkAI($local['sample_input'] === '2 3' && $local['sample_output'] === '5', 'extract exact samples');
checkAI(strpos($local['description'], 'Sample Input') === false, 'description does not duplicate all sections');
checkAI(count($local['test_cases']) === 1 && $local['test_cases'][0]['is_public'], 'local samples seed public test, no blank fabricated tests');
$longSample = AIHelper::parseLocal("Mảng\nMô tả\nTính tổng mảng.\nInput\nMột số n và n số.\nOutput\nTổng.\nSample Input\n4\n1\n2\n3\n4\nSample Output\n10");
checkAI($longSample['sample_input'] === "4\n1\n2\n3\n4", 'samples longer than three lines not truncated');
$incompleteError = '';
try { AIHelper::parseProblem('Given two integers, calculate their sum.'); }
catch (RuntimeException $e) { $incompleteError = $e->getMessage(); }
checkAI(strpos($incompleteError, 'Chưa cấu hình khóa AI') !== false && strpos($incompleteError, 'Các mục cần sinh') !== false, 'missing AI key rejects incomplete source rather than returning an empty preview');
$fallback = AIHelper::parseProblem($raw);
checkAI($fallback['source'] === 'local' && !$fallback['data']['ready_for_publish'] && $fallback['data']['needs_review'], 'complete source can be recovered as an honest review-only draft');
checkAI(count($fallback['data']['test_cases']) === 1 && count($fallback['data']['warnings']) > 0 && $fallback['data']['sample_output'] === '5', 'local recovery keeps actual samples without fabricating hidden tests');
$schema = AIHelper::problemSchema();
checkAI(array_diff(['title', 'description', 'input_desc', 'output_desc', 'sample_input', 'sample_output', 'test_cases'], $schema['required']) === [], 'Gemini schema requires all problem sections and test cases');
checkAI($schema['properties']['test_cases']['items']['required'] === ['stdin', 'expected', 'is_public', 'is_boundary'], 'Gemini schema requires complete test-case records');
$draft = [
    'title' => 'Tổng hai số', 'description' => 'Cho hai số nguyên a, b. Tính a + b.',
    'input_desc' => 'Hai số nguyên a, b, mỗi số từ -100 đến 100.', 'output_desc' => 'In tổng a + b.',
    'sample_input' => '2 3', 'sample_output' => '5', 'time_limit_ms' => 1000,
    'assumptions' => ['Hai số trên cùng một dòng.'],
    'test_cases' => [
        ['stdin' => '0 0', 'expected' => '0', 'is_public' => 'false', 'is_boundary' => '1'],
        ['stdin' => '-2 3', 'expected' => '1', 'is_public' => false],
    ],
];
$normalized = AIHelper::decodeProblem("```json\n" . json_encode($draft, JSON_UNESCAPED_UNICODE) . "\n```");
checkAI($normalized['test_cases'][0]['stdin'] === '2 3' && $normalized['test_cases'][0]['is_public'], 'missing sample case added consistently');
checkAI(!$normalized['test_cases'][1]['is_public'] && $normalized['test_cases'][1]['is_boundary'], 'string false and boundary normalized correctly');
checkAI(AIHelper::draftIssues($normalized, true) === [], 'complete generated contract passes');
checkAI($normalized['assumptions'] === $draft['assumptions'], 'assumptions retained for review');
$withoutBoundary = $draft;
$withoutBoundary['test_cases'][0]['is_boundary'] = false;
checkAI(count(AIHelper::draftIssues(AIHelper::normalize($withoutBoundary), true)) > 0, 'missing hidden boundary case flagged');
$conflicting = $draft;
$conflicting['test_cases'][] = ['stdin' => '2 3', 'expected' => '6'];
checkAI(count(AIHelper::draftIssues(AIHelper::normalize($conflicting))) > 0, 'sample/test output conflict surfaced');
$conflicting = $draft;
$conflicting['test_cases'][] = ['stdin' => '0 0', 'expected' => '1'];
checkAI(count(AIHelper::draftIssues(AIHelper::normalize($conflicting))) > 0, 'duplicate input conflicting output surfaced');
$english = $draft;
$english['title'] = 'Sum'; $english['description'] = 'Given two integers, print their sum.';
$english['input_desc'] = 'Two integers'; $english['output_desc'] = 'Print the sum';
checkAI(in_array('Nội dung đề chưa được dịch sang tiếng Việt', AIHelper::draftIssues(AIHelper::normalize($english), true), true), 'untranslated response marked incomplete');
$bad = AIHelper::normalize(['test_cases' => [null, ['stdin' => [], 'expected' => []]], 'title' => [], 'description' => []]);
checkAI($bad['test_cases'] === [] && $bad['title'] === '', 'invalid field types safely normalized');
$noInput = AIHelper::normalize(['title' => 'Xin chào', 'description' => 'In lời chào.', 'input_desc' => 'Không có dữ liệu đầu vào.', 'output_desc' => 'In Hello.', 'sample_input' => '', 'sample_output' => 'Hello']);
checkAI(AIHelper::draftIssues($noInput, true) === [] && $noInput['test_cases'][0]['stdin'] === '', 'no-input problem allowed without fabricated stdin');
$threw = false;
try { AIHelper::decodeProblem('{"title":"cut off'); } catch (Throwable $e) { $threw = true; }
checkAI($threw, 'truncated JSON refused');
checkAI(strpos(AIHelper::problemPrompt(), 'TIẾNG VIỆT') !== false && strpos(AIHelper::problemPrompt(), 'TỰ SINH') !== false, 'prompt requires translation and missing test generation');
echo "AI problem checks: $checks passed.\n";
