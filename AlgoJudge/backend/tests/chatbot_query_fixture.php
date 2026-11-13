<?php
/** CLI-only synthetic data for chatbot_query_http_tests.cjs. Never reads .env or drops a schema. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$name = getenv('AJ_DB_NAME') ?: '';
$port = filter_var(getenv('AJ_DB_PORT'), FILTER_VALIDATE_INT);
if (!preg_match('/^algojudge_test_ai_[a-zA-Z0-9_]+$/D', $name) || !$port) {
    fwrite(STDERR, "Refusing a non-test database or implicit port.\n"); exit(1);
}
$db = new PDO('mysql:host=127.0.0.1;port=' . $port . ';dbname=' . $name . ';charset=utf8mb4', getenv('AJ_DB_USER') ?: 'root', getenv('AJ_DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$action = $argv[1] ?? 'seed';
if ($action === 'user-state') {
    $change = json_decode($argv[2] ?? '', true, 16, JSON_THROW_ON_ERROR);
    $q = $db->prepare("SELECT id FROM users WHERE id=? AND username LIKE 'ai!_test!_%' ESCAPE '!'");
    $q->execute([(int)($change['id'] ?? 0)]);
    if (!$q->fetchColumn()) throw new RuntimeException('Only a synthetic fixture account may change.');
    $sets = []; $params = [];
    if (isset($change['role'])) {
        if (!in_array($change['role'], ['student', 'teacher', 'admin'], true)) throw new RuntimeException('Invalid role.');
        $sets[] = 'role=?'; $params[] = $change['role'];
    }
    if (isset($change['is_active'])) { $sets[] = 'is_active=?'; $params[] = (int)(bool)$change['is_active']; }
    if (!empty($change['bump_auth_version'])) $sets[] = 'auth_version=auth_version+1';
    if (!$sets) throw new RuntimeException('No fixture account change requested.');
    $params[] = (int)$change['id'];
    $db->prepare('UPDATE users SET ' . implode(',', $sets) . ' WHERE id=?')->execute($params);
    echo json_encode(['ok' => true]); exit;
}
if ($action !== 'seed') throw new RuntimeException('Unknown fixture command.');
foreach (['classes', 'problems', 'submissions', 'chatbot_sessions'] as $table) {
    if ((int)$db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() !== 0) throw new RuntimeException('Expected the fresh ai_http_fixture schema; existing data is retained.');
}
if ((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 3) throw new RuntimeException('Expected only the three ai_http_fixture accounts.');
$users = [];
foreach (['admin', 'teacher', 'student'] as $key) {
    $q = $db->prepare('SELECT id,username,role FROM users WHERE username=?'); $q->execute(['ai_test_' . $key]);
    $row = $q->fetch();
    if (!$row) throw new RuntimeException('Missing base fixture account.');
    $row['id'] = (int)$row['id']; $users[$key] = $row;
}
$db->beginTransaction();
try {
    $insert = $db->prepare('INSERT INTO users (username,email,password_hash,full_name,role,class_code,xp_points) VALUES (?,?,?,?,?,?,?)');
    $hash = password_hash('Ai-test-only-2026', PASSWORD_DEFAULT);
    foreach (['teacher2' => 'teacher', 'student2' => 'student', 'pending' => 'student', 'removed' => 'student', 'foreign' => 'student', 'legacy' => 'student'] as $key => $role) {
        $username = 'ai_test_' . $key;
        $insert->execute([$username, $username . '@example.test', $hash, 'Synthetic identical display name', $role, 'QUERY_LEGACY_ONLY', 0]);
        $users[$key] = ['id' => (int)$db->lastInsertId(), 'username' => $username, 'role' => $role];
    }
    $db->prepare('UPDATE users SET xp_points=?,class_code=?,full_name=? WHERE id=?')->execute([37, 'QUERY_LEGACY_ONLY', 'Synthetic identical display name', $users['student']['id']]);
    $classes = [];
    $q = $db->prepare('INSERT INTO classes (name,owner_teacher_id,created_by,legacy_class_code) VALUES (?,?,?,?)');
    foreach (['a' => ['AI Trùng tên', 'teacher'], 'b' => ['AI Trùng tên', 'teacher'], 'empty' => ['AI Lớp rỗng', 'teacher'], 'foreign' => ['AI Lớp ngoài quyền', 'teacher2'], 'foreign_duplicate' => ['AI Trùng tên', 'teacher2']] as $key => [$label, $owner]) {
        $q->execute([$label, $users[$owner]['id'], $users[$owner]['id'], $key === 'a' ? 'QUERY_LEGACY_ONLY' : null]);
        $classes[$key] = ['id' => (int)$db->lastInsertId(), 'name' => $label, 'owner' => $owner];
    }
    $q = $db->prepare('INSERT INTO class_members (class_id,user_id,status) VALUES (?,?,?)');
    foreach ([['a','student','active'], ['b','student','active'], ['a','student2','active'], ['a','pending','pending'], ['a','removed','removed'], ['foreign','foreign','active'], ['foreign','pending','active'], ['foreign','removed','active'], ['foreign_duplicate','foreign','active'], ['a','teacher2','active']] as [$class, $student, $status]) {
        $q->execute([$classes[$class]['id'], $users[$student]['id'], $status]);
    }
    $problems = [];
    $p = $db->prepare('INSERT INTO problems (slug,title,topic,difficulty,is_active,created_by) VALUES (?,?,?,?,?,?)');
    $v = $db->prepare('INSERT INTO problem_versions (problem_id,version_number,description,input_desc,output_desc,sample_input,sample_output,created_by) VALUES (?,1,?,?,?,?,?,?)');
    $t = $db->prepare('INSERT INTO test_cases (problem_version_id,case_order,stdin_data,expected_output,is_public,is_boundary) VALUES (?,?,?,?,?,?)');
    foreach (['array' => ['AI Mảng công khai','Mảng','Easy',1], 'hard' => ['AI Mảng nâng cao','Mảng','Hard',1], 'string' => ['AI Chuỗi công khai','Xử lý chuỗi','Medium',1], 'duplicate_a' => ['AI Bài trùng tên','Cơ bản','Easy',1], 'duplicate_b' => ['AI Bài trùng tên','Cơ bản','Easy',1], 'hidden' => ['QUERY_HIDDEN_PROBLEM_CANARY','Mảng','Easy',0]] as $key => [$title, $topic, $difficulty, $active]) {
        $p->execute(['query-' . $key, $title, $topic, $difficulty, $active, $users['teacher']['id']]);
        $id = (int)$db->lastInsertId();
        $v->execute([$id, 'Synthetic statement for read-only tests.', 'A single integer.', 'Print that integer.', '1', '1', $users['teacher']['id']]);
        $version = (int)$db->lastInsertId();
        $db->prepare('UPDATE problems SET current_version_id=? WHERE id=?')->execute([$version, $id]);
        $t->execute([$version, 1, '1', '1', 1, 0]);
        $t->execute([$version, 2, 'QUERY_HIDDEN_STDIN_CANARY_' . $key, 'QUERY_HIDDEN_EXPECTED_CANARY_' . $key, 0, 1]);
        $problems[$key] = ['id' => $id, 'version_id' => $version, 'hidden_case_id' => (int)$db->lastInsertId(), 'title' => $title, 'topic' => $topic, 'difficulty' => $difficulty, 'is_active' => $active];
    }
    $clock = $db->query('SELECT CURRENT_DATE AS today, DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY) AS yesterday, DATE_ADD(CURRENT_DATE, INTERVAL 1 DAY) AS tomorrow')->fetch();
    $submissions = []; $runs = [];
    $s = $db->prepare('INSERT INTO submissions (idempotency_key,user_id,problem_id,problem_version_id,language,source_code,final_verdict,final_score,pass_count,total_cases,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $r = $db->prepare('INSERT INTO judging_runs (submission_id,problem_version_id,run_number,trigger_type,triggered_by,status,verdict,score,pass_count,total_cases,system_error_detail,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $spec = [
        ['self_yesterday','student','array','AC','yesterday','23:59:59'],
        ['self_midnight','student','array','AC','today','00:00:00'],
        ['self_wa','student','array','WA','today','12:00:00'],
        ['self_queue','student','string','QUEUED','today','12:01:00'],
        ['self_running','student','string','RUNNING','today','12:02:00'],
        ['self_error','student','array','SYS_ERR','today','12:03:00'],
        ['self_tomorrow','student','array','AC','tomorrow','00:00:00'],
        ['second_ac','student2','array','AC','today','13:00:00'],
        ['second_wa','student2','string','WA','today','13:01:00'],
        ['second_yesterday','student2','string','WA','yesterday','00:00:00'],
        ['second_queue','student2','string','QUEUED','today','13:02:00'],
        ['pending_queue','pending','hidden','QUEUED','today','14:00:00'],
        ['removed_running','removed','hidden','RUNNING','today','14:01:00'],
        ['foreign_queue','foreign','hidden','QUEUED','today','14:02:00'],
        ['legacy_error','legacy','hidden','SYS_ERR','today','14:03:00'],
        ['teacher_queue','teacher','array','QUEUED','today','14:04:00'],
        ['teacher2_running','teacher2','hidden','RUNNING','today','14:05:00'],
        ['admin_error','admin','hidden','SYS_ERR','today','14:06:00'],
        ['foreign_ac','foreign','hidden','AC','today','14:07:00'],
    ];
    foreach ($spec as [$key, $who, $problem, $verdict, $day, $time]) {
        $at = $clock[$day] . ' ' . $time; $pb = $problems[$problem];
        $score = $verdict === 'AC' ? 100 : 0; $passed = $verdict === 'AC' ? 2 : 0;
        $s->execute(['query-' . $key, $users[$who]['id'], $pb['id'], $pb['version_id'], 'python', 'QUERY_PRIVATE_SOURCE_CANARY_' . $key, $verdict, $score, $passed, 2, $at]);
        $id = (int)$db->lastInsertId();
        $status = ['QUEUED' => 'QUEUED', 'RUNNING' => 'RUNNING', 'SYS_ERR' => 'SYS_ERROR'][$verdict] ?? 'COMPLETED';
        $r->execute([$id, $pb['version_id'], 1, 'INITIAL', $users[$who]['id'], $status, in_array($verdict, ['QUEUED','RUNNING'], true) ? 'PENDING' : $verdict, $score, $passed, 2, 'QUERY_INTERNAL_ERROR_CANARY', $at]);
        $runId = (int)$db->lastInsertId();
        $runs[] = ['id' => $runId, 'user' => $who, 'status' => $status, 'created_at' => $at];
        if ($key === 'self_midnight') {
            $db->prepare('INSERT INTO case_results (judging_run_id,test_case_id,verdict,actual_output) VALUES (?,?,?,?)')->execute([$runId, $pb['hidden_case_id'], 'AC', 'QUERY_HIDDEN_ACTUAL_CANARY']);
        }
        if ($key === 'second_queue') {
            $r->execute([$id, $pb['version_id'], 2, 'SYS_RETRY', $users[$who]['id'], 'QUEUED', 'PENDING', 0, 0, 2, 'QUERY_INTERNAL_ERROR_CANARY', $at]);
            $runId = (int)$db->lastInsertId();
            $runs[] = ['id' => $runId, 'user' => $who, 'status' => 'QUEUED', 'created_at' => $at];
        }
        $db->prepare('UPDATE submissions SET latest_run_id=? WHERE id=?')->execute([$runId, $id]);
        $submissions[$key] = ['id' => $id, 'user' => $who, 'problem' => $problem, 'verdict' => $verdict, 'created_at' => $at];
    }
    $db->commit();
    echo json_encode(['database' => $name, 'users' => $users, 'classes' => $classes, 'problems' => $problems, 'clock' => $clock, 'submissions' => $submissions, 'runs' => $runs], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
