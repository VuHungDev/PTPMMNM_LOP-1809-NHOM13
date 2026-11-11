<?php
// Read-only syntax check for application PHP (does not bootstrap or execute the app).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
$failed = 0;
$total = 0;
foreach (['backend', 'frontend'] as $dir) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') continue;
        $total++;
        $lines = [];
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $lines, $code);
        if ($code !== 0) { echo implode("\n", $lines), "\n"; $failed++; }
    }
}
echo "PHP lint: $total files; $failed failures.\n";
exit($failed ? 1 : 0);
