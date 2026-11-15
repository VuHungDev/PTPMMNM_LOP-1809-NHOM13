<?php
/** CLI-only reference validation, using the same mandatory Docker runner. No DB or .env. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/catalog.php';
require_once dirname(__DIR__) . '/lib/DockerSandbox.php';
require_once dirname(__DIR__) . '/lib/OutputComparator.php';
$options = getopt('', ['language:', 'structure-only', 'report:']);
$language = $options['language'] ?? null;
$languages = ['python', 'c', 'cpp', 'java', 'php', 'js'];
if ($language !== null && !in_array($language, $languages, true)) {
    fwrite(STDERR, "Unknown --language; use python, c, cpp, java, php or js.\n");
    exit(2);
}
$catalog = reference_standard_catalog();
$checks = 0; $failures = []; $observations = []; $programs = [];
$assert = static function (bool $ok, string $message) use (&$checks): void {
    $checks++;
    if (!$ok) throw new RuntimeException($message);
};
$readSource = static function (string $relative, string $lang) use ($assert): string {
    $assert(!str_contains($relative, '\\') && !str_contains($relative, '..') && str_starts_with($relative, $lang . '/'), 'Unsafe reference path: ' . $relative);
    $root = realpath(__DIR__);
    $file = realpath(__DIR__ . '/' . $relative);
    $assert($file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR . $lang . DIRECTORY_SEPARATOR) && is_file($file), 'Missing/outside reference file: ' . $relative);
    $source = file_get_contents($file);
    $assert(is_string($source) && $source !== '' && strlen($source) <= DockerSandbox::MAX_SOURCE_BYTES, 'Invalid source size: ' . $relative);
    return $source;
};
$execute = static function (string $lang, string $source, array $cases, int $time, int $memory) use ($assert): array {
    $prepared = DockerSandbox::prepare($lang, $source, $memory);
    $assert($prepared['ok'], 'Compile failed: ' . json_encode($prepared, JSON_UNESCAPED_UNICODE));
    $results = [];
    try {
        foreach ($cases as $case) {
            $result = DockerSandbox::run($prepared['program'], $case['stdin'], $time);
            $assert($result['verdict'] === 'OK', 'Runtime failed: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
            $results[] = $result;
        }
    } finally {
        $assert(DockerSandbox::release($prepared['program']), 'Reference sandbox cleanup failed.');
    }
    return $results;
};
foreach ($languages as $lang) {
    if ($language !== null && $language !== $lang) continue;
    try {
        $manifest = json_decode(file_get_contents(__DIR__ . '/' . $lang . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $assert(($manifest['language'] ?? null) === $lang, 'Manifest language mismatch.');
        $assert(is_array($manifest['programs'] ?? null) && count($manifest['programs']) === 10, 'Expected 10 programs in ' . $lang);
        $seen = [];
        foreach ($manifest['programs'] as $entry) {
            $slug = $entry['slug'] ?? '';
            $assert(isset($catalog[$slug]) && $catalog[$slug]['language'] === $lang && !isset($seen[$slug]), 'Wrong/duplicate standard slug: ' . $slug);
            $seen[$slug] = true;
            $correct = $readSource($entry['correct'], $lang);
            $wrong = $readSource($entry['wrong'], $lang);
            $assert($correct !== $wrong, 'AC and WA sources are identical for ' . $slug);
            $counterexample = $entry['counterexample'] ?? null;
            $assert(is_array($counterexample) && is_string($counterexample['stdin'] ?? null) && is_string($counterexample['expected_stdout'] ?? null)
                && is_string($counterexample['explanation'] ?? null) && $counterexample['explanation'] !== '', 'Missing explicit wrong-solution counterexample: ' . $slug);
            $cases = reference_validation_cases($catalog[$slug]);
            foreach ($cases as $case) {
                if ($case['dataset_issue'] !== null) $observations[] = ['slug' => $slug] + $case;
            }
            $cases[] = ['stdin' => $counterexample['stdin'], 'expected_stdout' => $counterexample['expected_stdout'],
                'legacy_expected_stdout' => null, 'origin' => 'WA_counterexample', 'dataset_issue' => null];
            if (!isset($options['structure-only'])) {
                $results = $execute($lang, $correct, $cases, (int)$catalog[$slug]['time_limit_ms'], (int)$catalog[$slug]['memory_limit_kb']);
                foreach ($results as $i => $result) {
                    $assert(OutputComparator::matches($result['stdout'], $cases[$i]['expected_stdout']), 'AC mismatch ' . $slug . ' ' . $cases[$i]['origin'] . ': ' . json_encode(['input' => $cases[$i]['stdin'], 'expected' => $cases[$i]['expected_stdout'], 'actual' => $result['stdout']], JSON_UNESCAPED_UNICODE));
                }
                $wrongResult = $execute($lang, $wrong, [$counterexample], (int)$catalog[$slug]['time_limit_ms'], (int)$catalog[$slug]['memory_limit_kb'])[0];
                $assert(!OutputComparator::matches($wrongResult['stdout'], $counterexample['expected_stdout']), 'WA example unexpectedly correct: ' . $slug);
                $programs[] = ['slug' => $slug, 'language' => $lang, 'correct_cases' => count($cases), 'wrong_counterexample' => true];
            } else {
                $programs[] = ['slug' => $slug, 'language' => $lang, 'structure_only' => true];
            }
            echo 'PASS ' . $lang . ' ' . $slug . (isset($options['structure-only']) ? ' structure' : ' AC/WA') . PHP_EOL;
        }
        foreach (['CE', 'RE', 'TLE'] as $verdict) {
            $source = $readSource($manifest['diagnostics'][$verdict] ?? '', $lang);
            if (!isset($options['structure-only'])) {
                $prepared = DockerSandbox::prepare($lang, $source, DockerSandbox::DEFAULT_MEMORY_KB);
                if ($verdict === 'CE') {
                    if ($prepared['ok']) $assert(DockerSandbox::release($prepared['program']), 'Unexpectedly valid CE sample cleanup failed.');
                    $assert(!$prepared['ok'] && $prepared['type'] === 'CE', 'CE example did not cause a compilation error: ' . $lang);
                } else {
                    $assert($prepared['ok'], 'Diagnostic failed compilation: ' . $lang . '/' . $verdict);
                    try {
                        $result = DockerSandbox::run($prepared['program'], '', $verdict === 'TLE' ? 300 : 2000);
                        $assert($result['verdict'] === $verdict, 'Diagnostic verdict mismatch ' . $lang . '/' . $verdict . ': ' . json_encode($result));
                    } finally {
                        $assert(DockerSandbox::release($prepared['program']), 'Diagnostic sandbox cleanup failed.');
                    }
                }
            }
            echo 'PASS ' . $lang . ' diagnostic ' . $verdict . PHP_EOL;
        }
    } catch (Throwable $e) {
        $failures[] = ['language' => $lang, 'message' => $e->getMessage()];
        fwrite(STDERR, 'FAIL ' . $lang . ': ' . $e->getMessage() . PHP_EOL);
    }
}
$report = ['structure_only' => isset($options['structure-only']), 'checks' => $checks, 'failures' => $failures,
    'programs' => $programs, 'legacy_dataset_issues' => $observations,
    'policy' => 'Statements are authoritative for these reference fixtures. Existing database/test history was not modified.'];
if (isset($options['report'])) {
    $destination = $options['report'];
    if (!is_string($destination) || !is_dir(dirname($destination))) throw new RuntimeException('Report requires a new file in an existing directory.');
    $handle = @fopen($destination, 'xb');
    if ($handle === false) throw new RuntimeException('Report file must be new and writable; existing files are never overwritten.');
    try {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        $offset = 0;
        while ($offset < strlen($json)) {
            $written = fwrite($handle, substr($json, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('Could not write the complete verification report.');
            $offset += $written;
        }
        if (!fflush($handle)) throw new RuntimeException('Could not flush the verification report.');
    } finally {
        fclose($handle);
    }
}
echo 'Reference checks: ' . $checks . '; exercises: ' . count($programs) . '; failures: ' . count($failures) . '; legacy dataset observations: ' . count($observations) . PHP_EOL;
exit($failures ? 1 : 0);
