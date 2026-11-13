<?php
/** Synthetic workspace fixture and test-only PHP prepend. Never loads .env or the working schema. */
$name = getenv('AJ_DB_NAME') ?: '';
$host = getenv('AJ_DB_HOST') ?: '';
$port = (int)(getenv('AJ_DB_PORT') ?: 0);
$token = getenv('AJ_WORKSPACE_FIXTURE_TOKEN') ?: '';
if (!preg_match('/^algojudge_test_workspace_[a-zA-Z0-9_]+$/D', $name)
    || $host !== '127.0.0.1' || $port !== 3307 || !preg_match('/^[a-f0-9]{48}$/D', $token)) {
    throw new RuntimeException('Refusing workspace verification without a unique test schema, loopback:3307, and fixture token.');
}
// Also applies to workers spawned by the real API through inherited PHP_INI_SCAN_DIR.
// Leave production bootstrap unchanged, but do not read project .env secrets in this test.
if (!function_exists('aj_load_env')) {
    function aj_load_env(string $dir): void {}
}
$main = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
if (strtolower(str_replace('\\', '/', $main)) !== strtolower(str_replace('\\', '/', __FILE__))) {
    return;
}
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$db = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', getenv('AJ_DB_USER') ?: 'root', getenv('AJ_DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    $q = $db->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $q->execute([$name]);
    if ($q->fetchColumn()) { throw new RuntimeException('Refusing to overwrite existing schema ' . $name); }
    $sql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    if (substr_count($sql, 'CREATE DATABASE IF NOT EXISTS algojudge ') !== 1 || substr_count($sql, 'USE algojudge;') !== 1) {
        throw new RuntimeException('Unexpected schema.sql database directives; refusing to execute.');
    }
    $sql = str_replace(['CREATE DATABASE IF NOT EXISTS algojudge ', 'USE algojudge;'], ["CREATE DATABASE `$name` ", "USE `$name`;"], $sql);
    $db->exec($sql); // Current fresh schema already includes v2-v10, no legacy migrations needed.
    $db->exec('CREATE TABLE workspace_test_guard (token CHAR(48) NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $db->prepare('INSERT INTO workspace_test_guard (token) VALUES (?)')->execute([$token]);
    $source = "n = int(input(\"Nhập N: \"))\nfactorial = 1\nfor i in range(2, n + 1):\n    factorial *= i\nprint(f\"{n}! = {factorial}\")\n";
    $db->prepare("INSERT INTO users (username,email,password_hash,full_name,role) VALUES (?, ?, ?, ?, 'student')")
        ->execute(['workspace_synthetic', 'workspace_synthetic@example.test', password_hash('Workspace-test-2026', PASSWORD_DEFAULT), 'Synthetic workspace learner']);
    $uid = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO problems (slug,title,topic,suggested_lang,difficulty,created_by) VALUES ('synthetic-workspace-factorial','Synthetic factorial — output verification','Synthetic test','python','Easy',?)")->execute([$uid]);
    $pid = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO problem_versions (problem_id,version_number,description,input_desc,output_desc,sample_input,sample_output,time_limit_ms,memory_limit_kb,created_by) VALUES (?,1,?,?,?,?,?,2000,65536,?)")
        ->execute([$pid, 'Tính giai thừa N. Đây là dữ liệu kiểm thử tổng hợp, không phải bài của người học.', 'Một số nguyên N (0 <= N <= 13).', 'Chỉ in N!, không in lời nhắc hoặc nhãn.', "5\n", "120\n", $uid]);
    $vid = (int)$db->lastInsertId();
    $db->prepare('UPDATE problems SET current_version_id = ? WHERE id = ?')->execute([$vid, $pid]);
    $case = $db->prepare('INSERT INTO test_cases (problem_version_id,case_order,stdin_data,expected_output,is_public,is_boundary,points) VALUES (?,?,?,?,?,?,20)');
    foreach ([["5\n", "120\n", 1, 0], ["6\n", "720\n", 1, 0], ["0000000000000000000000000000\n", "1\n", 0, 1], ["0000000000000000000000000007\n", "5040\n", 0, 0], ["0000000000000000000000000013\n", "6227020800\n", 0, 1]] as $i => $c) {
        $case->execute([$vid, $i + 1, $c[0], $c[1], $c[2], $c[3]]);
    }
    $db->prepare("INSERT INTO drafts (user_id,problem_id,language,source_code) VALUES (?,?,'python',?)")->execute([$uid, $pid, $source]);
    echo json_encode(['schema' => $name, 'user_id' => $uid, 'problem_id' => $pid, 'version_id' => $vid, 'username' => 'workspace_synthetic', 'password' => 'Workspace-test-2026', 'wrong_source' => $source], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit;
}
if ($mode !== 'inspect') { throw new RuntimeException('Expected seed or inspect mode.'); }
$db->exec("USE `$name`");
$q = $db->prepare('SELECT token FROM workspace_test_guard WHERE token = ?');
$q->execute([$token]);
if (!$q->fetchColumn()) { throw new RuntimeException('Fixture ownership token mismatch; refusing to inspect.'); }
// Every query below is scoped to this run-owned, newly created synthetic database.
$counts = [];
foreach (['users', 'problems', 'test_cases', 'drafts', 'submissions', 'judging_runs', 'custom_runs'] as $table) {
    $counts[$table] = (int)$db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
}
$drafts = $db->query('SELECT user_id,problem_id,language,source_code FROM drafts ORDER BY id')->fetchAll();
$submissions = $db->query('SELECT id,source_code,final_verdict,final_score,pass_count,total_cases FROM submissions ORDER BY id')->fetchAll();
$runs = $db->query('SELECT id,submission_id,status,verdict,pass_count,total_cases FROM judging_runs ORDER BY id')->fetchAll();
$custom = $db->query('SELECT id,status,verdict,stdout_output,stderr_output FROM custom_runs ORDER BY id')->fetchAll();
echo json_encode(['schema' => $name, 'counts' => $counts, 'drafts' => $drafts, 'submissions' => $submissions, 'runs' => $runs, 'custom_runs' => $custom], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit;
