<?php
/** CLI-only merge of completed per-language verifier reports. No DB/Docker or code execution. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 3 || !is_dir($argv[1]) || file_exists($argv[2])) {
    fwrite(STDERR, "Usage: php finalize_verification.php <completed-report-directory> <new-output-json>\n");
    exit(2);
}
$languages = ['python','c','cpp','java','php','js'];
$checks = 0; $exercises = 0; $byLanguage = []; $observations = [];
foreach ($languages as $language) {
    $path = rtrim($argv[1], '/\\') . DIRECTORY_SEPARATOR . $language . '.json';
    if (!is_file($path)) throw new RuntimeException('Missing completed verification report: ' . $language);
    $report = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($report['structure_only'] ?? true) !== false || ($report['failures'] ?? null) !== [] || count($report['programs'] ?? []) !== 10) {
        throw new RuntimeException('Unsuccessful/incomplete language report: ' . $language);
    }
    foreach ($report['programs'] as $program) {
        if (($program['language'] ?? '') !== $language || ($program['wrong_counterexample'] ?? false) !== true) throw new RuntimeException('Report language or counterexample missing.');
    }
    $checks += (int)$report['checks']; $exercises += count($report['programs']);
    $byLanguage[$language] = ['checks' => (int)$report['checks'], 'exercises' => 10, 'failures' => 0, 'report_sha256' => hash_file('sha256', $path)];
    $observations = array_merge($observations, $report['legacy_dataset_issues'] ?? []);
}
$sourceHash = hash_init('sha256');
foreach ($languages as $language) {
    $manifestPath = __DIR__ . '/' . $language . '/manifest.json';
    $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    hash_update_file($sourceHash, $manifestPath);
    foreach ($manifest['programs'] as $entry) foreach (['correct','wrong'] as $key) hash_update_file($sourceHash, __DIR__ . '/' . $entry[$key]);
    foreach ($manifest['diagnostics'] as $relative) hash_update_file($sourceHash, __DIR__ . '/' . $relative);
}
foreach (['catalog.php','verify.php'] as $file) hash_update_file($sourceHash, __DIR__ . '/' . $file);
$result = ['verified_on' => date('Y-m-d'), 'execution_backend' => 'DockerSandbox (Linux containers, non-root, no network)',
    'scope' => '60 standard exercises, 10 per language; 60 AC + 60 WA + 18 shared diagnostic programs',
    'standalone_correct_sources' => 60, 'standalone_wrong_sources' => 60, 'intentional_diagnostics' => ['CE'=>6,'RE'=>6,'TLE'=>6],
    'verification_run' => ['checks'=>$checks,'exercises'=>$exercises,'failures'=>0,'legacy_dataset_observations'=>count($observations)],
    'by_language' => $byLanguage, 'current_sources_and_tools_sha256' => hash_final($sourceHash),
    'coverage' => ['original seed inputs','statement-corrected legacy cases','unique-pair v2 boundary inputs','Unicode boundaries','manifest WA counterexamples','CE/RE/TLE diagnostics'],
    'notes' => ['Reports must be from the final source version; this merger cannot prove source files were unchanged during verification.',
        'Verification and this merger do not modify any database, source draft, submission, verdict or XP.',
        'Expected outputs in historical seed fixtures remain recorded separately from statement-correct reference expectations.']];
$json = json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR) . PHP_EOL;
$stream = @fopen($argv[2], 'xb');
if ($stream === false) throw new RuntimeException('Output must be a new writable file.');
try {
    $offset=0;
    while ($offset<strlen($json)) {
        $written=fwrite($stream,substr($json,$offset));
        if ($written===false||$written===0) throw new RuntimeException('Incomplete merged report write.');
        $offset+=$written;
    }
    if (!fflush($stream)) throw new RuntimeException('Cannot flush merged report.');
} finally { fclose($stream); }
echo "Completed reports: 6; exercises: $exercises; checks: $checks; failures: 0\n";
