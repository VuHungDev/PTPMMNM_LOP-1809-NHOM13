<?php
/** Offline output regressions: no bootstrap, JudgeEngine, database or dependencies. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/OutputComparator.php';

$outputTests = 0;
$outputChecks = 0;
$outputFailures = 0;

function checkJudgeOutput(bool $ok, string $message): void
{
    global $outputChecks;
    $outputChecks++;
    if (!$ok) throw new RuntimeException($message);
}

function judgeOutputTest(string $label, callable $test): void
{
    global $outputTests, $outputFailures;
    $outputTests++;
    try {
        $test();
        echo "PASS $label\n";
    } catch (Throwable $e) {
        $outputFailures++;
        fwrite(STDERR, "FAIL $label: " . $e->getMessage() . "\n");
    }
}

/** Run only our Python fixtures, without a shell or any temporary source files. */
function runJudgeOutputPython(array $arguments, string $stdin): array
{
    $python = getenv('AJ_TEST_PYTHON');
    if ($python === false || $python === '') {
        $python = PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
    }
    $environment = getenv();
    $environment['PYTHONIOENCODING'] = 'utf-8';
    $pipes = [];
    $process = proc_open(
        // -I ignores PYTHON* variables; explicitly enable UTF-8 for Unicode prompts.
        array_merge([$python, '-I', '-X', 'utf8'], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        __DIR__,
        $environment,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start Python; set AJ_TEST_PYTHON to a Python 3 executable.');
    }

    try {
        if (fwrite($pipes[0], $stdin) !== strlen($stdin)) {
            throw new RuntimeException('Could not send the complete Python test input.');
        }
        fclose($pipes[0]);
        // These owned fixtures emit only a few bytes, so neither output pipe can fill.
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        if ($stdout === false || $stderr === false) {
            throw new RuntimeException('Could not capture Python output.');
        }
    } finally {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) fclose($pipe);
        }
        $exitCode = proc_close($process);
    }

    return ['stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $exitCode];
}

// Lock down trim -> CRLF conversion -> per-line rtrim, including its asymmetries.
$normalizationCases = [
    ['plain number', '120', '120'],
    ['terminal LF', "120\n", '120'],
    ['terminal CRLF', "120\r\n", '120'],
    ['multiple terminal newlines', "120\n\n\n", '120'],
    ['outer whitespace', " \t\r\n120 \t\r\n\r\n", '120'],
    ['CRLF and per-line trailing spaces', "120 \t\r\n720 \t\r\n", "120\n720"],
    ['first-line indentation alone is trimmed', "  120\n 720\n", "120\n 720"],
    ['internal spaces remain', "1  20\n", '1  20'],
    ['internal blank lines remain', "120\n\n720\n", "120\n\n720"],
    ['internal lone CR remains', "1\r20\n", "1\r20"],
    ['whitespace-only output', " \t\r\n\n", ''],
];
foreach ($normalizationCases as [$label, $actual, $expected]) {
    judgeOutputTest("normalize: $label", function () use ($actual, $expected): void {
        checkJudgeOutput(OutputComparator::normalize($actual) === $expected, 'Normalization changed existing semantics.');
    });
}

$comparisonCases = [
    ['correct factorial', "120\n", '120', true],
    ['CRLF accepted', "120\r\n", "120\n", true],
    ['trailing spaces accepted', "120 \t\n", '120', true],
    ['terminal newlines accepted', "120\n\n", '120', true],
    ['CRLF and trailing spaces on every line accepted', "120 \r\n720\t\r\n", "120\n720", true],
    ['expected output is normalized too', '120', " \t120 \r\n", true],
    ['input prompt rejected', "Nhap n: 120\n", '120', false],
    ['equation rejected', "5! = 120\n", '120', false],
    ['prompt and equation rejected', "Nhap n: 5! = 120\n", '120', false],
    ['separate prompt and equation lines rejected', "Nhap n:\n5! = 120\n", '120', false],
    ['wrong number rejected', "121\n", '120', false],
    ['duplicate number on separate lines rejected', "120\n120\n", '120', false],
    ['duplicate number on one line rejected', "120 120\n", '120', false],
    ['internal space rejected', "1 20\n", '120', false],
    ['different internal spacing rejected', "120  720\n", '120 720', false],
    ['spaces instead of line breaks rejected', "120 720\n", "120\n720", false],
    ['extra internal blank line rejected', "120\n\n720\n", "120\n720", false],
    ['indentation on a later line rejected', "120\n 720\n", "120\n720", false],
    ['leading zero rejected', "0120\n", '120', false],
    ['numeric-equivalent decimal rejected', "120.0\n", '120', false],
];
foreach ($comparisonCases as [$label, $actual, $expected, $matches]) {
    judgeOutputTest("compare: $label", function () use ($actual, $expected, $matches): void {
        checkJudgeOutput(OutputComparator::matches($actual, $expected) === $matches, 'Unexpected AC/WA result.');
        checkJudgeOutput(OutputComparator::matches($expected, $actual) === $matches, 'Comparison must be symmetric.');
    });
}

$reference = dirname(__DIR__) . '/reference_solutions/python_factorial.py';
// Strings avoid platform integer overflow and are independent of the implementation.
$factorials = [
    '1', '1', '2', '6', '24', '120', '720', '5040', '40320', '362880',
    '3628800', '39916800', '479001600', '6227020800', '87178291200',
    '1307674368000', '20922789888000', '355687428096000',
    '6402373705728000', '121645100408832000', '2432902008176640000',
];
foreach ($factorials as $n => $expected) {
    judgeOutputTest("Python reference: $n!", function () use ($reference, $n, $expected): void {
        $result = runJudgeOutputPython([$reference], "$n\n");
        checkJudgeOutput($result['exit_code'] === 0, 'Python exited with ' . $result['exit_code'] . ': ' . $result['stderr']);
        checkJudgeOutput($result['stderr'] === '', 'Reference solution wrote to stderr.');
        checkJudgeOutput(OutputComparator::matches($result['stdout'], $expected), 'Reference solution must receive AC.');
        checkJudgeOutput(in_array($result['stdout'], [$expected . "\n", $expected . "\r\n"], true), 'Reference must print only the integer and a newline.');
    });
}

// Reuse the real algorithm, but deliberately restore the faulty presentation.
// run_path does not invoke the reference's __main__ guard and writes no fixture files.
$fixturePrelude = <<<'PYTHON'
import runpy
import sys
giai_thua = runpy.run_path(sys.argv[1])["giai_thua"]
PYTHON;
$pythonVariants = [
    ['input prompt', 'n = int(input("Nhập n: "))' . "\n" . 'print(giai_thua(n))', "Nhập n: 120\n"],
    ['equation', 'n = int(input())' . "\n" . 'print(f"{n}! = {giai_thua(n)}")', "5! = 120\n"],
    ['input prompt and equation', 'n = int(input("Nhập n: "))' . "\n" . 'print(f"{n}! = {giai_thua(n)}")', "Nhập n: 5! = 120\n"],
];
foreach ($pythonVariants as [$label, $body, $expectedStdout]) {
    judgeOutputTest("real Python rejects: $label", function () use ($reference, $fixturePrelude, $body, $expectedStdout): void {
        $result = runJudgeOutputPython(['-c', $fixturePrelude . "\n" . $body, $reference], "5\n");
        checkJudgeOutput($result['exit_code'] === 0, 'Python fixture exited with ' . $result['exit_code'] . ': ' . $result['stderr']);
        checkJudgeOutput($result['stderr'] === '', 'Python fixture wrote to stderr.');
        checkJudgeOutput(OutputComparator::normalize($result['stdout']) === OutputComparator::normalize($expectedStdout), 'Python must actually emit the faulty presentation.');
        checkJudgeOutput(!OutputComparator::matches($result['stdout'], '120'), 'Prompts/equations must receive WA, not be stripped.');
    });
}

echo "Tests: $outputTests; checks: $outputChecks; failures: $outputFailures\n";
exit($outputFailures === 0 ? 0 : 1);
