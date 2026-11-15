<?php
/** Explicit CLI-only, additive repair. Never updates old versions/cases or rejudges submissions. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/catalog.php';
$options = getopt('', ['apply', 'actor-id:']);
$database = getenv('AJ_DB_NAME') ?: 'algojudge';
$host = getenv('AJ_DB_HOST') ?: '127.0.0.1';
$port = getenv('AJ_DB_PORT') ?: '3307';
if (!in_array($host, ['127.0.0.1', 'localhost'], true) || !ctype_digit($port) || !preg_match('/\A(?:algojudge|algojudge_test_[A-Za-z0-9_]+)\z/', $database)) {
    throw new RuntimeException('Explicit local AlgoJudge database configuration required.');
}
$pdo = new PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4", getenv('AJ_DB_USER') ?: 'root', getenv('AJ_DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) throw new RuntimeException('Unexpected database.');
$catalog = reference_standard_catalog();
$slugs = ['c-mult-table', 'cpp-two-sum-idx', 'js-two-sum', 'js-palindrome-str'];
$changelog = 'Sửa test theo đề bài: bảng cửu chương đủ N dòng, palindrome phân biệt hoa thường, Two Sum đúng một cặp. Giữ nguyên lịch sử v1.';
$plans = [];
foreach ($slugs as $slug) {
    $stmt = $pdo->prepare('SELECT p.id, p.title, p.current_version_id, pv.* FROM problems p JOIN problem_versions pv ON pv.id = p.current_version_id WHERE p.slug = ?');
    $stmt->execute([$slug]); $current = $stmt->fetch();
    if (!$current) throw new RuntimeException('Missing standard problem: ' . $slug);
    $stmt = $pdo->prepare('SELECT * FROM test_cases WHERE problem_version_id = ? ORDER BY case_order, id');
    $stmt->execute([$current['current_version_id']]); $cases = $stmt->fetchAll();
    $standard = $catalog[$slug]['cases'];
    if (count($cases) !== count($standard)) throw new RuntimeException('Test count differs from known catalog; review manually: ' . $slug);
    $correctedPairs = array_slice(reference_validation_cases($catalog[$slug]), 0, count($standard));
    if ($slug === 'cpp-two-sum-idx') {
        $correctedPairs[3]['stdin'] = "5 10\n1 2 3 7 20"; $correctedPairs[3]['expected_stdout'] = '2 3';
        $correctedPairs[4]['stdin'] = "6 0\n-1 1 2 3 4 5"; $correctedPairs[4]['expected_stdout'] = '0 1';
    } elseif ($slug === 'js-two-sum') {
        $correctedPairs[4]['stdin'] = "6 7\n1 10 20 30 40 6"; $correctedPairs[4]['expected_stdout'] = '0 5';
    }
    if ($current['changelog'] === $changelog) {
        foreach ($cases as $i => $case) {
            if ($case['stdin_data'] !== $correctedPairs[$i]['stdin'] || $case['expected_output'] !== $correctedPairs[$i]['expected_stdout']) {
                throw new RuntimeException('Correction label exists but current cases differ; review manually: ' . $slug);
            }
        }
        echo 'SKIP already corrected and verified ' . $slug . PHP_EOL;
        continue;
    }
    foreach ($cases as $i => $case) {
        if ($case['stdin_data'] !== $standard[$i][0] || $case['expected_output'] !== $standard[$i][1]) {
            throw new RuntimeException('Current test differs from known legacy input/output; refusing overwrite: ' . $slug . ' case ' . ($i + 1));
        }
    }
    $fixed = $cases;
    foreach (reference_validation_cases($catalog[$slug]) as $i => $reference) {
        if ($i >= count($cases)) break;
        $fixed[$i]['expected_output'] = $reference['expected_stdout'];
    }
    if ($slug === 'cpp-two-sum-idx') {
        $fixed[3]['stdin_data'] = "5 10\n1 2 3 7 20"; $fixed[3]['expected_output'] = '2 3';
        $fixed[4]['stdin_data'] = "6 0\n-1 1 2 3 4 5"; $fixed[4]['expected_output'] = '0 1';
    } elseif ($slug === 'js-two-sum') {
        $fixed[4]['stdin_data'] = "6 7\n1 10 20 30 40 6"; $fixed[4]['expected_output'] = '0 5';
    }
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM problem_versions WHERE problem_id = ?');
    $stmt->execute([(int)$current['problem_id']]); $next = (int)$stmt->fetchColumn();
    $plans[] = ['slug' => $slug, 'current' => $current, 'old_cases' => $cases, 'new_cases' => $fixed, 'next_version' => $next];
    echo 'PLAN ' . $slug . ': v' . $current['version_number'] . ' -> v' . $next . ', ' . count($fixed) . ' cases; old cases untouched.' . PHP_EOL;
}
if (!isset($options['apply'])) {
    echo 'Dry run only. Pass --apply --actor-id=<existing admin ID> to create new versions.' . PHP_EOL;
    exit;
}
$actorId = filter_var($options['actor-id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$actorId) throw new RuntimeException('An explicit admin actor ID is required for application.');
$pdo->beginTransaction();
try {
    // Do not hold an actor lock before problem/version locks: ordinary instructor
    // dataset mutations lock problem first, then AuditLog locks the actor. A
    // shared FK lock on this same actor could otherwise invert that order.
    $stmt = $pdo->prepare("SELECT id, role, is_active FROM users WHERE id = ?");
    $stmt->execute([$actorId]); $actor = $stmt->fetch();
    if (!$actor || $actor['role'] !== 'admin' || (int)$actor['is_active'] !== 1) throw new RuntimeException('Active administrative actor required.');
    foreach ($plans as $plan) {
        $old = $plan['current'];
        $stmt = $pdo->prepare('SELECT current_version_id FROM problems WHERE id = ? FOR UPDATE');
        $stmt->execute([(int)$old['problem_id']]);
        if ((int)$stmt->fetchColumn() !== (int)$old['current_version_id']) throw new RuntimeException('Version changed during review: ' . $plan['slug']);
        $stmt = $pdo->prepare('SELECT * FROM test_cases WHERE problem_version_id = ? ORDER BY case_order, id FOR UPDATE');
        $stmt->execute([(int)$old['current_version_id']]);
        if ($stmt->fetchAll() !== $plan['old_cases']) throw new RuntimeException('Legacy cases changed during review.');
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM problem_versions WHERE problem_id = ?');
        $stmt->execute([(int)$old['problem_id']]);
        if ((int)$stmt->fetchColumn() !== $plan['next_version']) throw new RuntimeException('Version numbering changed during review.');
        $stmt = $pdo->prepare('INSERT INTO problem_versions (problem_id,version_number,description,input_desc,output_desc,sample_input,sample_output,time_limit_ms,memory_limit_kb,changelog,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([(int)$old['problem_id'], $plan['next_version'], $old['description'], $old['input_desc'], $old['output_desc'], $old['sample_input'], $old['sample_output'], (int)$old['time_limit_ms'], (int)$old['memory_limit_kb'], $changelog, $actorId]);
        $versionId = (int)$pdo->lastInsertId();
        $insert = $pdo->prepare('INSERT INTO test_cases (problem_version_id,case_order,stdin_data,expected_output,is_public,is_boundary,points) VALUES (?,?,?,?,?,?,?)');
        foreach ($plan['new_cases'] as $case) $insert->execute([$versionId, (int)$case['case_order'], $case['stdin_data'], $case['expected_output'], (int)$case['is_public'], (int)$case['is_boundary'], (int)$case['points']]);
        $pdo->prepare('UPDATE problems SET current_version_id = ? WHERE id = ?')->execute([$versionId, (int)$old['problem_id']]);
        $pdo->prepare("INSERT INTO admin_activity_logs (actor_id,action,target_type,target_id,metadata) VALUES (?,'dataset.create','problem',?,?)")
            ->execute([$actorId, (int)$old['problem_id'], json_encode(['version_number' => $plan['next_version'], 'count' => count($plan['new_cases']), 'source' => 'ui'], JSON_THROW_ON_ERROR)]);
        $check = $pdo->prepare('SELECT * FROM test_cases WHERE problem_version_id = ? ORDER BY case_order, id');
        $check->execute([(int)$old['current_version_id']]);
        if ($check->fetchAll() !== $plan['old_cases']) throw new RuntimeException('Historical cases changed unexpectedly.');
        echo 'CREATED ' . $plan['slug'] . ' new version #' . $versionId . PHP_EOL;
    }
    $stmt = $pdo->prepare('SELECT role, is_active FROM users WHERE id = ? FOR UPDATE');
    $stmt->execute([$actorId]); $currentActor = $stmt->fetch();
    if (!$currentActor || $currentActor['role'] !== 'admin' || (int)$currentActor['is_active'] !== 1) {
        throw new RuntimeException('Administrative actor changed during correction; refusing commit.');
    }
    $pdo->commit();
    echo 'Applied additive corrections; original versions, submissions, verdicts, drafts and XP were not modified.' . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
