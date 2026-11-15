<?php
/**
 * Mandatory Linux Docker boundary for BOTH compilers and contestant programs.
 * No host toolchain, shell command supplied by a caller, bind mount or image pull.
 * Images are operator-provisioned, audited, immutable sha256 references; absent
 * configuration/engine/images fail closed as SYS_ERR.
 */
final class DockerSandbox
{
    public const MAX_SOURCE_BYTES = 262144;
    public const MAX_STDIN_BYTES = 1048576;
    public const MAX_STDOUT_BYTES = 65536;
    public const MAX_STDERR_BYTES = 32768;
    public const CUSTOM_TIME_LIMIT_MS = 10000;
    public const CUSTOM_MEMORY_KB = 262144;
    public const DEFAULT_MEMORY_KB = 65536;
    public const JAVA_OVERHEAD_KB = 131072;
    private const COMPILE_TIME_MS = 30000;
    private const MAX_TIME_MS = 60000;
    private const ARTIFACT_BYTES = 67108864;
    private const LABEL = 'algojudge.sandbox=mandatory-v1';
    private const PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/opt/java/openjdk/bin';
    private static array $jobs = [];
    private static array $images = [];
    private static array $containers = [];
    private static bool $shutdownRegistered = false;

    private const FILES = [
        'c' => 'solution.c', 'cpp' => 'solution.cpp', 'java' => 'Main.java',
        'python' => 'solution.py', 'php' => 'solution.php', 'js' => 'solution.js',
    ];

    public static function prepare(string $language, string $source, int $memoryKb = self::DEFAULT_MEMORY_KB): array
    {
        // Synchronous fallback can outlive Apache's short request deadline;
        // container/compiler deadlines, not PHP termination, govern this work.
        // A client disconnect must not abandon a claimed run or its resources.
        ignore_user_abort(true);
        @set_time_limit(0);
        $token = null;
        try {
            if (!isset(self::FILES[$language])) throw new RuntimeException('Unsupported sandbox language.');
            if (strlen($source) > self::MAX_SOURCE_BYTES) throw new RuntimeException('Source exceeds the 256 KiB sandbox limit.');
            self::validateMemory($memoryKb);
            $image = self::verifiedImage($language);
            $token = bin2hex(random_bytes(16));
            $volume = 'aj_sandbox_' . $token;
            self::$jobs[$token] = ['volume' => $volume, 'holder' => $volume . '_hold', 'image' => $image, 'language' => $language, 'memory_kb' => $memoryKb];
            if (!self::$shutdownRegistered) {
                register_shutdown_function([self::class, 'cleanupAll']);
                self::$shutdownRegistered = true;
            }
            $created = self::cli([
                'volume', 'create', '--driver', 'local', '--label', self::LABEL,
                '--opt', 'type=tmpfs', '--opt', 'device=tmpfs',
                '--opt', 'o=size=' . self::ARTIFACT_BYTES . ',uid=65534,gid=65534,mode=0700,nosuid,nodev', $volume,
            ]);
            self::requireSuccess($created, 'Cannot create the bounded tmpfs artifact volume.');
            if (trim($created['stdout']) !== $volume) throw new RuntimeException('Unexpected artifact volume identity.');
            // A local tmpfs volume is emptied when its last running mount closes.
            // Keep a source-less, equally isolated lease container mounted until
            // all tests finish. It never reads artifacts or receives contestant input.
            $holderJob = self::$jobs[$token];
            $holderJob['language'] = 'python';
            $holderJob['memory_kb'] = 16384;
            $holder = self::$jobs[$token]['holder'];
            self::$containers[$holder] = true;
            self::requireSuccess(self::cli(self::containerArguments($holderJob, $holder, ['/bin/sleep', '900'], 900000, false)), 'Cannot create the tmpfs lease container.');
            self::requireSuccess(self::cli(['start', $holder]), 'Cannot start the tmpfs lease container.');
            self::$jobs[$token]['expires_at'] = microtime(true) + 890;
            $result = self::container(self::$jobs[$token], self::compileCommand($language), $source, self::COMPILE_TIME_MS, true);
            if ($result['infrastructure_error'] !== '') throw new RuntimeException($result['infrastructure_error']);
            if ($result['exit_code'] !== 0 || $result['timed_out'] || $result['output_limit']) {
                // 125 is reserved by our fixed compiler preflight, not source text.
                if (in_array($result['exit_code'], [125, 126, 127], true) && !$result['timed_out']) {
                    throw new RuntimeException('Sandbox image is missing a required compiler/interpreter or runner.');
                }
                $message = trim($result['stderr'] . "\n" . $result['stdout']);
                if ($result['timed_out']) $message = 'Compilation time limit exceeded.';
                if ($result['output_limit']) $message = 'Compiler output limit exceeded.';
                if ($message === '') $message = 'Compilation failed (sandbox resource limit or compiler error).';
                if (!self::release(['sandbox' => 'docker-v1', 'token' => $token])) throw new RuntimeException('Cannot remove the compilation artifact volume.');
                return ['ok' => false, 'type' => 'CE', 'message' => self::storageText($message, self::MAX_STDERR_BYTES)];
            }
            return ['ok' => true, 'program' => ['sandbox' => 'docker-v1', 'token' => $token]];
        } catch (Throwable $e) {
            if ($token !== null) self::release(['sandbox' => 'docker-v1', 'token' => $token]);
            return ['ok' => false, 'type' => 'SYS_ERR', 'message' => self::infrastructureMessage($e)];
        }
    }

    /** $program is an opaque in-process capability, never a command or mount path. */
    public static function run($program, string $stdin, int $timeLimitMs): array
    {
        try {
            $job = self::job($program);
            if (strlen($stdin) > self::MAX_STDIN_BYTES) throw new RuntimeException('Input exceeds the 1 MiB sandbox limit.');
            if ($timeLimitMs < 1 || $timeLimitMs > self::MAX_TIME_MS) throw new RuntimeException('Unsupported sandbox time limit (1..60000 ms).');
            if (($job['expires_at'] ?? 0) < microtime(true) + $timeLimitMs / 1000 + 5) throw new RuntimeException('Sandbox artifact lease expired; retry in a new judging run.');
            $lease = self::cli(['inspect', '--format', '{{.State.Running}}', $job['holder']]);
            self::requireSuccess($lease, 'The sandbox artifact lease is unavailable.');
            if (trim($lease['stdout']) !== 'true') throw new RuntimeException('The sandbox artifact lease stopped; retry in a new judging run.');
            $result = self::container($job, self::runtimeCommand($job), $stdin, $timeLimitMs, false);
            if ($result['infrastructure_error'] !== '') throw new RuntimeException($result['infrastructure_error']);
            $verdict = 'OK';
            if ($result['timed_out']) {
                $verdict = 'TLE';
                $result['stderr'] = 'Time Limit Exceeded';
            } elseif ($result['output_limit']) {
                $verdict = 'RE';
                $result['stderr'] = 'Output limit exceeded (stdout 64 KiB / stderr 32 KiB).';
            } elseif ($result['oom_killed']) {
                $verdict = 'RE';
                $result['stderr'] = 'Memory limit exceeded.';
            } elseif ($result['exit_code'] !== 0) {
                $verdict = 'RE';
            }
            return [
                'verdict' => $verdict, 'time_ms' => $result['timed_out'] ? $timeLimitMs : $result['time_ms'],
                'exit_code' => $result['exit_code'], 'stdout' => $result['stdout'], 'stderr' => $result['stderr'],
            ];
        } catch (Throwable $e) {
            return ['verdict' => 'SYS_ERR', 'time_ms' => 0, 'exit_code' => -1, 'stdout' => '', 'stderr' => self::infrastructureMessage($e)];
        }
    }

    public static function release($program): bool
    {
        try { $job = self::job($program); } catch (Throwable $e) { return true; }
        $holderRemoved = self::removeContainer($job['holder']);
        $result = self::cli(['volume', 'rm', $job['volume']]);
        $ok = $holderRemoved && !$result['timed_out'] && !$result['output_limit'] &&
            ($result['exit_code'] === 0 || strpos($result['stderr'], 'no such volume') !== false);
        if ($ok) unset(self::$jobs[$program['token']]);
        return $ok;
    }

    public static function cleanupAll(): void
    {
        foreach (array_keys(self::$containers) as $name) self::removeContainer($name);
        foreach (array_keys(self::$jobs) as $token) self::release(['sandbox' => 'docker-v1', 'token' => $token]);
    }

    /** UTF-8-safe database diagnostics only; comparison always uses the raw stdout. */
    public static function storageText(string $text, int $maxBytes = 60000): string
    {
        if (function_exists('mb_convert_encoding')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            return mb_strcut($text, 0, $maxBytes, 'UTF-8');
        }
        $text = preg_replace('/[\x80-\xFF]/', '?', $text);
        return substr($text, 0, $maxBytes);
    }

    private static function job($program): array
    {
        if (!is_array($program) || ($program['sandbox'] ?? '') !== 'docker-v1' ||
            !is_string($program['token'] ?? null) || !isset(self::$jobs[$program['token']])) {
            throw new RuntimeException('Invalid or expired Docker sandbox program handle.');
        }
        return self::$jobs[$program['token']];
    }

    private static function validateMemory(int $memoryKb): void
    {
        if ($memoryKb < 16384 || $memoryKb > 1048576) throw new RuntimeException('Unsupported sandbox memory limit (16..1024 MiB).');
    }

    private static function verifiedImage(string $language): string
    {
        $ref = getenv('AJ_SANDBOX_IMAGE_' . strtoupper($language));
        if ($ref === false || $ref === '') {
            // Protected, nonsecret operator config, NEVER a request-controlled
            // path. There are deliberately no baked-in third-party image pins.
            $configFile = __DIR__ . '/../config/sandbox_images.php';
            $config = is_file($configFile) ? (static function (string $file) { return require $file; })($configFile) : [];
            $ref = is_array($config) ? ($config[$language] ?? null) : null;
        }
        // Tags/latest, options, whitespace and shell syntax are deliberately invalid.
        if (!is_string($ref) || !preg_match('/\A(?:[a-z0-9][a-z0-9._\/:\-]*@)?sha256:[a-f0-9]{64}\z/D', $ref)) {
            throw new RuntimeException('Configure an audited digest-pinned AJ_SANDBOX_IMAGE_' . strtoupper($language) . ' (no mutable tags).');
        }
        // Revalidate engine enforcement for each job, even in a long-lived
        // daemon whose immutable image identity was already cached.
        $info = self::cli(['info', '--format', '{{json .SecurityOptions}}|{{.MemoryLimit}}|{{.SwapLimit}}|{{.PidsLimit}}|{{.CPUCfsPeriod}}|{{.CPUCfsQuota}}|{{.OSType}}']);
        self::requireSuccess($info, 'Docker Linux engine is unavailable.');
        $parts = explode('|', trim($info['stdout']));
        if (count($parts) !== 7 || $parts[6] !== 'linux') throw new RuntimeException('The judge requires Linux containers.');
        $security = json_decode($parts[0], true);
        if (!is_array($security) || !in_array('name=seccomp,profile=builtin', $security, true) ||
            array_slice($parts, 1, 5) !== ['true', 'true', 'true', 'true', 'true']) {
            throw new RuntimeException('Docker must enforce seccomp, memory/swap, PID and CPU cgroup limits.');
        }
        if (isset(self::$images[$ref])) return self::$images[$ref];
        $image = self::cli(['image', 'inspect', '--format', '{{json .}}', $ref]);
        self::requireSuccess($image, 'The pinned sandbox image is not installed locally; automatic pulls are disabled.');
        $metadata = json_decode($image['stdout'], true);
        if (!is_array($metadata) || ($metadata['Os'] ?? '') !== 'linux' || !preg_match('/\Asha256:[a-f0-9]{64}\z/D', $metadata['Id'] ?? '')) {
            throw new RuntimeException('The sandbox image must have an immutable Linux image identity.');
        }
        if (!empty($metadata['Config']['Volumes'])) throw new RuntimeException('Sandbox images must not declare implicit/anonymous volumes.');
        return self::$images[$ref] = $metadata['Id'];
    }

    private static function compileCommand(string $language): array
    {
        $commands = [
            'c' => ['gcc', 'gcc -O2 -std=c11 -fdiagnostics-color=never /work/solution.c -o /work/solution.out'],
            'cpp' => ['g++', 'g++ -O2 -std=c++17 -fdiagnostics-color=never /work/solution.cpp -o /work/solution.out'],
            'java' => ['javac', 'javac -J-Xms16m -J-Xmx256m -J-XX:+UseSerialGC -J-XX:ActiveProcessorCount=1 -proc:none -encoding UTF-8 /work/Main.java'],
            'python' => ['python3', 'python3 -I -X utf8 -m py_compile /work/solution.py'],
            'php' => ['php', 'php -n -d display_errors=stderr -d log_errors=0 -l /work/solution.php'],
            'js' => ['node', 'node --check /work/solution.js'],
        ];
        [$tool, $command] = $commands[$language];
        // Only this fixed shell snippet is interpreted; the source arrives on stdin.
        $preflight = 'command -v ' . $tool . ' >/dev/null 2>&1 || exit 125; ';
        if ($language === 'java') $preflight .= 'command -v java >/dev/null 2>&1 || exit 125; ';
        return ['/bin/sh', '-c', $preflight . 'umask 077; cat > /work/' . self::FILES[$language] . ' && exec ' . $command];
    }

    private static function runtimeCommand(array $job): array
    {
        $memoryMb = max(16, intdiv($job['memory_kb'], 1024));
        switch ($job['language']) {
            case 'c': case 'cpp': return ['/work/solution.out'];
            case 'python': return ['python3', '-I', '-B', '-X', 'utf8', '/work/solution.py'];
            case 'java': return ['java', '-Xms16m', '-Xmx' . $memoryMb . 'm', '-Xss256k', '-XX:MaxMetaspaceSize=64m', '-XX:ReservedCodeCacheSize=32m', '-XX:+UseSerialGC', '-XX:ActiveProcessorCount=1', '-Djava.io.tmpdir=/tmp', '-Dfile.encoding=UTF-8', '-cp', '/work', 'Main'];
            case 'php': return ['php', '-n', '-d', 'memory_limit=' . $memoryMb . 'M', '-d', 'display_errors=stderr', '-d', 'log_errors=0', '/work/solution.php'];
            case 'js': return ['node', '--max-old-space-size=' . max(8, $memoryMb - 32), '/work/solution.js'];
        }
        throw new RuntimeException('Unsupported sandbox language.');
    }

    private static function containerArguments(array $job, string $name, array $command, int $limitMs, bool $compile): array
    {
        $memoryKb = $compile ? 524288 : $job['memory_kb'] + ($job['language'] === 'java' ? self::JAVA_OVERHEAD_KB : 0);
        $args = [
            'create', '--pull=never', '--name', $name, '--label', self::LABEL,
            '--network=none', '--ipc=none', '--cgroupns=private', '--user=65534:65534', '--cap-drop=ALL',
            '--security-opt=no-new-privileges:true', '--read-only', '--init', '--no-healthcheck',
            '--cpus=1', '--memory=' . ($memoryKb * 1024), '--memory-swap=' . ($memoryKb * 1024),
            // RLIMIT_NPROC counts the shared real UID across containers on engines
            // without userns remapping. Use only the per-container PID cgroup limit.
            '--pids-limit=64', '--ulimit=nofile=64:64',
            '--ulimit=core=0:0', '--ulimit=fsize=16777216:16777216',
            '--log-driver=none', '--workdir=/work',
            // Docker CLI may otherwise inject proxy credentials from its own
            // config, even without --env. Clear these before init also sees them.
            '--env=HTTP_PROXY=', '--env=HTTPS_PROXY=', '--env=ALL_PROXY=', '--env=NO_PROXY=', '--env=FTP_PROXY=',
            '--env=http_proxy=', '--env=https_proxy=', '--env=all_proxy=', '--env=no_proxy=', '--env=ftp_proxy=',
            '--tmpfs=/tmp:rw,noexec,nosuid,nodev,size=16777216,mode=1777',
            '--mount=type=volume,src=' . $job['volume'] . ',dst=/work,volume-nocopy' . ($compile ? '' : ',readonly'),
            '--interactive', '--entrypoint=/usr/bin/env', $job['image'],
            '-i', 'PATH=' . self::PATH, 'LANG=C.UTF-8', 'LC_ALL=C.UTF-8', 'HOME=/tmp', 'TMPDIR=/tmp',
            'OPENBLAS_NUM_THREADS=1', 'OMP_NUM_THREADS=1', 'MKL_NUM_THREADS=1',
            'NODE_PATH=/opt/judge/node_modules',
            '/usr/bin/timeout', '--signal=KILL', sprintf('%.3fs', $limitMs / 1000),
        ];
        return array_merge($args, $command);
    }

    private static function container(array $job, array $command, string $input, int $limitMs, bool $compile): array
    {
        $name = 'aj_sandbox_' . bin2hex(random_bytes(16));
        self::$containers[$name] = true;
        $result = [
            'exit_code' => -1, 'stdout' => '', 'stderr' => '', 'timed_out' => false,
            'output_limit' => false, 'oom_killed' => false, 'time_ms' => 0, 'infrastructure_error' => '',
        ];
        try {
            $create = self::cli(self::containerArguments($job, $name, $command, $limitMs, $compile));
            self::requireSuccess($create, 'Cannot create the hardened Docker container.');
            // timeout runs INSIDE the container, excluding Docker startup latency.
            // The host deadline also handles stopped/tampered timeout processes.
            $start = self::cli(['start', '--attach', '--interactive', $name], $input, $limitMs + 3000, self::MAX_STDOUT_BYTES, self::MAX_STDERR_BYTES, $name);
            $result['stdout'] = $start['stdout'];
            $result['stderr'] = $start['stderr'];
            $result['timed_out'] = $start['timed_out'];
            $result['output_limit'] = $start['output_limit'];
            // The host deadline/output guard force-removes the WHOLE container.
            if ($start['timed_out'] || $start['output_limit']) {
                if (!$start['container_removed']) throw new RuntimeException('Could not stop the sandbox container after a resource limit.');
                $result['exit_code'] = 137;
                $result['time_ms'] = $limitMs;
            } else {
                $inspect = self::cli(['inspect', '--format', '{{json .State}}', $name]);
                self::requireSuccess($inspect, 'Cannot read the sandbox execution state.');
                $state = json_decode($inspect['stdout'], true);
                if (!is_array($state) || !array_key_exists('ExitCode', $state) || ($state['Running'] ?? true) || ($state['Error'] ?? '') !== '') {
                    throw new RuntimeException('Docker could not start/finish the sandbox process.');
                }
                $result['exit_code'] = (int)$state['ExitCode'];
                $result['oom_killed'] = (bool)($state['OOMKilled'] ?? false);
                $begin = new DateTimeImmutable($state['StartedAt']);
                $end = new DateTimeImmutable($state['FinishedAt']);
                $result['time_ms'] = max(0, (int)round(((float)$end->format('U.u') - (float)$begin->format('U.u')) * 1000));
                $result['timed_out'] = !$result['oom_killed'] && $result['exit_code'] === 137 && $result['time_ms'] >= $limitMs;
                // Docker StartedAt/FinishedAt also includes init/shutdown overhead.
                // An exit-0 program that finished inside GNU timeout must not turn
                // into TLE merely because container teardown was slow under load.
                // The in-container deadline and independent host watchdog remain enforced.
                if ($start['exit_code'] !== $result['exit_code']) throw new RuntimeException('Docker attach disconnected before returning the execution result.');
            }
        } catch (Throwable $e) {
            $result['infrastructure_error'] = $e->getMessage();
        } finally {
            if (!self::removeContainer($name)) $result['infrastructure_error'] = 'Cannot remove the sandbox container; operator cleanup is required.';
        }
        return $result;
    }

    private static function removeContainer(string $name): bool
    {
        $removed = self::cli(['rm', '--force', $name]);
        $ok = !$removed['timed_out'] && !$removed['output_limit'] &&
            ($removed['exit_code'] === 0 || strpos($removed['stderr'], 'No such container') !== false);
        if ($ok) unset(self::$containers[$name]);
        return $ok;
    }

    private static function requireSuccess(array $result, string $message): void
    {
        if ($result['exit_code'] !== 0 || $result['timed_out'] || $result['output_limit']) throw new RuntimeException($message);
    }

    private static function infrastructureMessage(Throwable $e): string
    {
        $message = 'Docker sandbox unavailable (no host fallback): ' . $e->getMessage();
        if ($e->getMessage() === 'Docker Linux engine is unavailable.') {
            $message .= "\nDịch vụ chạy code chưa sẵn sàng. Trên máy chủ Windows, hãy mở Docker Desktop, đợi Engine running ở chế độ Linux containers rồi bấm Chạy lại. Không cần sửa mã nguồn của bạn.";
        }
        return self::storageText($message, self::MAX_STDERR_BYTES);
    }

    /**
     * Windows anonymous pipes can block before the monitor runs. Only trusted
     * Docker CLI processes run on the host, with array argv, bypass_shell and
     * private file-backed stdin/stdout/stderr. No contestant file is copied out.
     */
    private static function cli(array $arguments, string $stdin = '', int $timeoutMs = 10000, int $stdoutLimit = 65536, int $stderrLimit = 32768, ?string $container = null): array
    {
        $result = ['exit_code' => -1, 'stdout' => '', 'stderr' => '', 'timed_out' => false, 'output_limit' => false, 'container_removed' => false];
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aj_docker_cli_' . bin2hex(random_bytes(16));
        $process = null;
        try {
            if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot allocate private Docker CLI spools.');
            $in = $directory . DIRECTORY_SEPARATOR . 'stdin';
            $out = $directory . DIRECTORY_SEPARATOR . 'stdout';
            $err = $directory . DIRECTORY_SEPARATOR . 'stderr';
            if (file_put_contents($in, $stdin) !== strlen($stdin)) throw new RuntimeException('Cannot spool sandbox input.');
            $binary = getenv('AJ_SANDBOX_DOCKER');
            if (!is_string($binary) || $binary === '') {
                // Apache service PATH may not include Docker Desktop. Reuse the
                // same fixed protected nonsecret config, never a request path.
                $configFile = __DIR__ . '/../config/sandbox_images.php';
                $config = is_file($configFile) ? (static function (string $file) { return require $file; })($configFile) : [];
                $binary = is_array($config) ? ($config['docker_binary'] ?? '') : '';
            }
            if (!is_string($binary) || $binary === '') $binary = PHP_OS_FAMILY === 'Windows' ? 'docker.exe' : 'docker';
            $pipes = [];
            $process = @proc_open(array_merge([$binary], $arguments), [0 => ['file', $in, 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, __DIR__, self::cliEnvironment(), ['bypass_shell' => true, 'suppress_errors' => true]);
            if (!is_resource($process)) throw new RuntimeException('Cannot launch Docker CLI.');
            $deadline = microtime(true) + $timeoutMs / 1000;
            while (true) {
                $status = proc_get_status($process);
                clearstatcache(true, $out);
                clearstatcache(true, $err);
                $result['output_limit'] = (@filesize($out) > $stdoutLimit || @filesize($err) > $stderrLimit);
                $result['timed_out'] = $status['running'] && microtime(true) >= $deadline;
                if ($result['output_limit'] || $result['timed_out']) {
                    // Stop the local output writer FIRST so spools cannot grow
                    // while Docker removes the container and all descendants.
                    proc_terminate($process, 9);
                    if ($container !== null) $result['container_removed'] = self::removeContainer($container);
                    break;
                }
                if (!$status['running']) {
                    $result['exit_code'] = (int)$status['exitcode'];
                    break;
                }
                usleep(10000);
            }
            $closed = proc_close($process);
            $process = null;
            if ($result['exit_code'] === -1 && $closed !== -1) $result['exit_code'] = $closed;
            // Recheck after process exit as well: a short-lived flood can finish
            // between the last stat and proc_close without another monitor tick.
            clearstatcache(true, $out);
            clearstatcache(true, $err);
            $result['output_limit'] = $result['output_limit'] || @filesize($out) > $stdoutLimit || @filesize($err) > $stderrLimit;
            if ($result['output_limit'] && $container !== null && !$result['container_removed']) $result['container_removed'] = self::removeContainer($container);
            $result['stdout'] = (string)file_get_contents($out, false, null, 0, $stdoutLimit);
            $result['stderr'] = (string)file_get_contents($err, false, null, 0, $stderrLimit);
        } catch (Throwable $e) {
            $result['stderr'] = 'Cannot communicate with Docker CLI.';
            if (is_resource($process)) {
                proc_terminate($process, 9);
                if ($container !== null) $result['container_removed'] = self::removeContainer($container);
                proc_close($process);
            }
        } finally {
            foreach (['stdin', 'stdout', 'stderr'] as $file) @unlink($directory . DIRECTORY_SEPARATOR . $file);
            @rmdir($directory);
        }
        return $result;
    }

    private static function cliEnvironment(): array
    {
        $environment = [];
        // Required by the local Docker client, never forwarded into the container.
        foreach (['PATH', 'SystemRoot', 'WINDIR', 'USERPROFILE', 'HOME', 'LOCALAPPDATA', 'APPDATA', 'TEMP', 'TMP', 'DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_CONFIG', 'DOCKER_CERT_PATH', 'DOCKER_TLS_VERIFY'] as $name) {
            $value = getenv($name);
            if (is_string($value)) $environment[$name] = $value;
        }
        return $environment;
    }
}
