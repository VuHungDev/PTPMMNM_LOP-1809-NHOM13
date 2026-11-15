<?php
/** Trusted machine configuration only; never accept executable/root paths from a request. */
$local = __DIR__ . '/native_sandbox.local.php';
if (is_file($local)) return require $local;
$root = getenv('AJ_NATIVE_ROOT') ?: ((getenv('LOCALAPPDATA') ?: sys_get_temp_dir()) . '/AlgoJudgeNative');
$root = str_replace('\\', '/', $root);
return [
    'root' => $root,
    'helper' => $root . '/bin/AlgoJudge.NativeSandbox.exe',
    'tools' => [
        'c' => $root . '/runtimes/mingw/bin/gcc.exe',
        'cpp' => $root . '/runtimes/mingw/bin/g++.exe',
        'javac' => $root . '/runtimes/java/bin/javac.exe',
        'java' => $root . '/runtimes/java/bin/java.exe',
        'python' => $root . '/runtimes/python/python.exe',
        'php' => $root . '/runtimes/php/php.exe',
        'js' => $root . '/runtimes/node/node.exe',
    ],
];
