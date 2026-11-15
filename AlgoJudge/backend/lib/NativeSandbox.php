<?php
/** Windows AppContainer + Job Object boundary. No Docker, shells or unsafe host fallback. */
final class NativeSandbox
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
    private const FILES = ['c'=>'solution.c','cpp'=>'solution.cpp','java'=>'Main.java','python'=>'solution.py','php'=>'solution.php','js'=>'solution.js'];
    private static array $jobs = [];
    private static bool $shutdownRegistered = false;

    private static function config(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') throw new RuntimeException('Native sandbox requires Windows.');
        $cfg = require __DIR__ . '/../config/native_sandbox.php';
        if (!is_array($cfg) || !is_string($cfg['root'] ?? null) || !is_file($cfg['helper'] ?? '')) throw new RuntimeException('Native sandbox has not been provisioned.');
        return $cfg;
    }
    private static function writeFile(string $path, string $bytes): void
    {
        if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) throw new RuntimeException('Cannot prepare the isolated job.');
    }
    /** Invoke only the trusted helper by argument array; stdout is bounded control JSON. */
    private static function helper(array $cfg, array $arguments, int $deadlineMs = 15000): array
    {
        $pipes = [];
        $process = @proc_open(array_merge([$cfg['helper']], $arguments), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname($cfg['helper']), null, ['bypass_shell'=>true]);
        if (!is_resource($process)) throw new RuntimeException('Native sandbox helper unavailable.');
        fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $output = ''; $start = microtime(true); $code = -1;
        try {
            while (true) {
                $output .= stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]); // Do not expose coordinator paths/details.
                if (strlen($output) > 1048576) throw new RuntimeException('Invalid sandbox control response.');
                $state = proc_get_status($process);
                if (!$state['running']) { $code = $state['exitcode']; $output .= stream_get_contents($pipes[1]); break; }
                if ((microtime(true)-$start)*1000 > $deadlineMs) { proc_terminate($process); throw new RuntimeException('Native sandbox helper deadline exceeded.'); }
                usleep(10000);
            }
        } finally {
            fclose($pipes[1]); fclose($pipes[2]); $closed = proc_close($process);
        }
        if ($code !== 0 && $closed !== 0) throw new RuntimeException('Native sandbox operation failed.');
        $result = json_decode(trim($output), true);
        if (!is_array($result)) throw new RuntimeException('Invalid sandbox control response.');
        return $result;
    }
    private static function tool(array $cfg, string $name): string
    {
        $file = $cfg['tools'][$name] ?? '';
        if (!is_string($file) || !is_file($file)) throw new RuntimeException('Native language toolchain is unavailable.');
        return $file;
    }
    public static function prepare(string $language, string $source, int $memoryKb = self::DEFAULT_MEMORY_KB): array
    {
        ignore_user_abort(true); @set_time_limit(0); $token = null;
        try {
            if (!isset(self::FILES[$language])) throw new RuntimeException('Unsupported language.');
            if (strlen($source) > self::MAX_SOURCE_BYTES) throw new RuntimeException('Source exceeds 256 KiB.');
            if ($memoryKb < 16384 || $memoryKb > 1048576) throw new RuntimeException('Unsupported memory limit.');
            $cfg = self::config();
            self::tool($cfg, $language === 'java' ? 'javac' : $language);
            if ($language === 'java') self::tool($cfg, 'java');
            $created = self::helper($cfg, ['--create-job', $cfg['root']]);
            $token = $created['job_id'] ?? '';
            if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/D', $token)) throw new RuntimeException('Invalid sandbox job identity.');
            // Compute paths from the operator root and opaque id, never helper/user paths.
            $work = $cfg['root'] . '/jobs/' . $token;
            $control = $cfg['root'] . '/control/' . $token;
            self::$jobs[$token] = ['cfg'=>$cfg,'work'=>$work,'control'=>$control,'language'=>$language,'memory_kb'=>$memoryKb];
            if (!self::$shutdownRegistered) { register_shutdown_function([self::class,'cleanupAll']); self::$shutdownRegistered = true; }
            self::writeFile($work . '/' . self::FILES[$language], $source);
            [$executable, $args] = self::command(self::$jobs[$token], true);
            $r = self::execute(self::$jobs[$token], $executable, $args, '', self::COMPILE_TIME_MS, true);
            if ($r['infrastructure_error']) throw new RuntimeException('Native compilation isolation unavailable.');
            if ($r['exit_code'] !== 0 || $r['timed_out'] || $r['output_limit'] || $r['memory_limit']) {
                $message = $r['timed_out'] ? 'Compilation time limit exceeded.' : ($r['output_limit'] ? 'Compiler output limit exceeded.' : trim($r['stderr'] . "\n" . $r['stdout']));
                if (!self::release(['sandbox'=>'native-v1','token'=>$token])) throw new RuntimeException('Native sandbox cleanup failed.');
                return ['ok'=>false,'type'=>'CE','message'=>self::storageText($message ?: 'Compilation failed.',self::MAX_STDERR_BYTES)];
            }
            return ['ok'=>true,'program'=>['sandbox'=>'native-v1','token'=>$token]];
        } catch (Throwable $e) {
            if ($token !== null) self::release(['sandbox'=>'native-v1','token'=>$token]);
            return ['ok'=>false,'type'=>'SYS_ERR','message'=>self::infrastructureMessage()];
        }
    }
    private static function command(array $job, bool $compile): array
    {
        $cfg=$job['cfg']; $work=$job['work']; $lang=$job['language'];
        $file=$work.'/'.self::FILES[$lang]; $mb=max(16,intdiv($job['memory_kb'],1024));
        if ($compile) {
            switch ($lang) {
                case 'c': return [self::tool($cfg,'c'),['-O2','-std=c11','-fdiagnostics-color=never',$file,'-o',$work.'/solution.exe']];
                case 'cpp': return [self::tool($cfg,'cpp'),['-O2','-std=c++17','-fdiagnostics-color=never',$file,'-o',$work.'/solution.exe']];
                case 'java': return [self::tool($cfg,'javac'),['-J-Xms16m','-J-Xmx256m','-J-XX:+UseSerialGC','-J-XX:ActiveProcessorCount=1','-proc:none','-encoding','UTF-8',$file]];
                case 'python': return [self::tool($cfg,'python'),['-I','-X','utf8','-m','py_compile',$file]];
                case 'php': return [self::tool($cfg,'php'),['-n','-d','display_errors=stderr','-d','log_errors=0','-l',$file]];
                case 'js': return [self::tool($cfg,'js'),['--check',$file]];
            }
        }
        switch ($lang) {
            case 'c': case 'cpp': return [$work.'/solution.exe',[]];
            case 'python': return [self::tool($cfg,'python'),['-I','-B','-X','utf8',$file]];
            case 'java': return [self::tool($cfg,'java'),['-Xms16m','-Xmx'.$mb.'m','-Xss256k','-XX:MaxMetaspaceSize=64m','-XX:ReservedCodeCacheSize=32m','-XX:+UseSerialGC','-XX:ActiveProcessorCount=1','-Djava.io.tmpdir='.$work,'-Dfile.encoding=UTF-8','-cp',$work,'Main']];
            case 'php': return [self::tool($cfg,'php'),['-n','-d','memory_limit='.$mb.'M','-d','display_errors=stderr','-d','log_errors=0',$file]];
            case 'js': return [self::tool($cfg,'js'),['--max-old-space-size='.max(8,$mb-32),$file]];
        }
        throw new RuntimeException('Unsupported language.');
    }
    private static function execute(array $job, string $executable, array $args, string $stdin, int $limit, bool $compile): array
    {
        $base=$job['control']; $cfg=$job['cfg'];
        self::writeFile($base.'/stdin.bin',$stdin);
        foreach (['stdout.bin','stderr.bin','result.json'] as $name) if (is_file($base.'/'.$name)) unlink($base.'/'.$name);
        $payload=['sandboxRoot'=>$cfg['root'],'executable'=>$executable,'args'=>$args,'workDir'=>$job['work'],
            'stdinPath'=>$base.'/stdin.bin','stdoutPath'=>$base.'/stdout.bin','stderrPath'=>$base.'/stderr.bin','resultPath'=>$base.'/result.json',
            'timeLimitMs'=>$limit,'memoryLimitKb'=>$compile?524288:$job['memory_kb']+($job['language']==='java'?self::JAVA_OVERHEAD_KB:0),
            'maxStdoutBytes'=>self::MAX_STDOUT_BYTES,'maxStderrBytes'=>self::MAX_STDERR_BYTES,'phase'=>$compile?'compile':'run'];
        self::writeFile($base.'/request.json',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $r=self::helper($cfg,[$base.'/request.json'],$limit+15000);
        if (!isset($r['time_ms']) && is_file($base.'/result.json')) $r=json_decode(file_get_contents($base.'/result.json'),true);
        foreach (['exit_code','timed_out','output_limit','memory_limit','time_ms','infrastructure_error'] as $field) if (!is_array($r)||!array_key_exists($field,$r)) throw new RuntimeException('Incomplete sandbox result.');
        $r['stdout']=is_file($base.'/stdout.bin')?file_get_contents($base.'/stdout.bin',false,null,0,self::MAX_STDOUT_BYTES):'';
        $r['stderr']=is_file($base.'/stderr.bin')?file_get_contents($base.'/stderr.bin',false,null,0,self::MAX_STDERR_BYTES):'';
        return $r;
    }
    private static function job($program): array
    {
        if (!is_array($program)||($program['sandbox']??'')!=='native-v1'||!is_string($program['token']??null)||!isset(self::$jobs[$program['token']])) throw new RuntimeException('Invalid or expired native program handle.');
        return self::$jobs[$program['token']];
    }
    public static function run($program,string $stdin,int $timeLimitMs): array
    {
        try {
            $job=self::job($program);
            if (strlen($stdin)>self::MAX_STDIN_BYTES||$timeLimitMs<1||$timeLimitMs>60000) throw new RuntimeException('Unsupported execution bounds.');
            [$exe,$args]=self::command($job,false); $r=self::execute($job,$exe,$args,$stdin,$timeLimitMs,false);
            if ($r['infrastructure_error']) throw new RuntimeException('Native execution isolation unavailable.');
            $verdict=$r['timed_out']?'TLE':(($r['output_limit']||$r['memory_limit']||$r['exit_code']!==0)?'RE':'OK');
            $stderr=$r['timed_out']?'Time Limit Exceeded':($r['output_limit']?'Output limit exceeded (stdout 64 KiB / stderr 32 KiB).':($r['memory_limit']?'Memory limit exceeded.':$r['stderr']));
            return ['verdict'=>$verdict,'time_ms'=>$r['timed_out']?$timeLimitMs:(int)$r['time_ms'],'exit_code'=>(int)$r['exit_code'],'stdout'=>$r['stdout'],'stderr'=>$stderr];
        } catch (Throwable $e) { return ['verdict'=>'SYS_ERR','time_ms'=>0,'exit_code'=>-1,'stdout'=>'','stderr'=>self::infrastructureMessage()]; }
    }
    public static function release($program): bool
    {
        try { $job=self::job($program); } catch (Throwable $e) { return true; }
        try { self::helper($job['cfg'],['--release-job',$job['cfg']['root'],$program['token']]); unset(self::$jobs[$program['token']]); return true; }
        catch (Throwable $e) { return false; }
    }
    public static function cleanupAll(): void { foreach (array_keys(self::$jobs) as $token) self::release(['sandbox'=>'native-v1','token'=>$token]); }
    public static function storageText(string $text,int $maxBytes=60000): string
    {
        if (function_exists('mb_convert_encoding')) return mb_strcut(mb_convert_encoding($text,'UTF-8','UTF-8'),0,$maxBytes,'UTF-8');
        return substr(preg_replace('/[\x80-\xFF]/','?',$text),0,$maxBytes);
    }
    public static function infrastructureMessage(): string { return 'Bộ thực thi native Windows chưa sẵn sàng hoặc không bảo đảm cách ly. Kiểm tra cấu hình/helper và toolchain; không chạy mã trực tiếp ngoài sandbox.'; }
}
