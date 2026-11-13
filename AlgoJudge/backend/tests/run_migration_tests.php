<?php
/** Reconstruct a synthetic pre-v7 database, then check v7/v8 without touching a working DB. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$name = getenv('AJ_DB_NAME') ?: '';
$port = (int)(getenv('AJ_DB_PORT') ?: 0);
if (!preg_match('/^algojudge_test_[a-zA-Z0-9_]+$/D', $name) || !$port) {
    fwrite(STDERR, "Set a new algojudge_test_* schema and an isolated AJ_DB_PORT.\n"); exit(1);
}
$db = new PDO('mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4', getenv('AJ_DB_USER') ?: 'root', getenv('AJ_DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$exists = $db->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
$exists->execute([$name]);
if ($exists->fetchColumn()) { fwrite(STDERR, "Refusing to overwrite $name.\n"); exit(1); }
$root = dirname(__DIR__, 2);
$sql = file_get_contents($root . '/backend/database/schema.sql');
foreach (['chatbot_creations', 'chatbot_sessions', 'classes', 'class_members', 'password_reset_otps', 'password_reset_requests', 'admin_activity_logs'] as $table) {
    $sql = preg_replace('/CREATE TABLE ' . $table . ' \([\s\S]*?\) ENGINE=InnoDB;/', '', $sql, 1, $count);
    if ($count !== 1) throw new RuntimeException('Fixture schema no longer matches ' . $table);
}
$sql = preg_replace('/^    auth_version INT UNSIGNED NOT NULL DEFAULT 1,\r?\n/m', '', $sql, 1, $count);
if ($count !== 1) throw new RuntimeException('Fixture auth_version mismatch');
$sql = str_replace("class_code VARCHAR(30) NOT NULL DEFAULT ''", "class_code VARCHAR(30) NOT NULL DEFAULT 'OLD-DEFAULT'", $sql);
$sql = str_replace(['CREATE DATABASE IF NOT EXISTS algojudge ', 'USE algojudge;'], ["CREATE DATABASE `$name` ", "USE `$name`;"], $sql);
$db->exec($sql);
$rows = [
    ['admin', 'admin', 'ALL'], ['clear_teacher', 'teacher', 'CLEAR'],
    ['ambiguous_a', 'teacher', 'AMBIGUOUS'], ['ambiguous_b', 'teacher', 'AMBIGUOUS'],
    ['wildcard', 'teacher', 'ALL'], ['clear_student', 'student', 'CLEAR'],
    ['ambiguous_student', 'student', 'AMBIGUOUS'], ['orphan_student', 'student', 'ORPHAN'],
    ['all_student', 'student', 'ALL'], ['empty_student', 'student', ''],
];
$insert = $db->prepare('INSERT INTO users (username,email,password_hash,full_name,role,class_code,xp_points) VALUES (?,?,?,?,?,?,17)');
foreach ($rows as [$username, $role, $code]) $insert->execute([$username, $username . '@example.test', password_hash('Migration-pass-2026', PASSWORD_DEFAULT), $username, $role, $code]);
$db->exec("INSERT INTO problems (slug,title,topic,created_by) VALUES ('legacy-problem','Legacy problem','Test',2)");
$db->exec("INSERT INTO problem_versions (problem_id,version_number,description,input_desc,output_desc,sample_input,sample_output,created_by) VALUES (1,1,'Test','Test','Test','1','1',2)");
$db->exec('UPDATE problems SET current_version_id = 1 WHERE id = 1');
$db->exec("INSERT INTO submissions (idempotency_key,user_id,problem_id,problem_version_id,language,source_code,final_verdict) VALUES ('legacy-submission',6,1,1,'python','print(1)','AC')");
$usersBefore = $db->query('SELECT id,username,email,password_hash,role,class_code,xp_points FROM users ORDER BY id')->fetchAll();
$submissionsBefore = $db->query('SELECT * FROM submissions ORDER BY id')->fetchAll();
$m7 = file_get_contents($root . '/backend/database/migrations/migrate_v7_password_reset.sql');
$m8 = file_get_contents($root . '/backend/database/migrations/migrate_v8_classes.sql');
$db->exec($m7); $db->exec($m8);
$checks = 0;
function migrationCheck(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $label); $checks++; echo "PASS $label\n"; }
migrationCheck($db->query('SELECT id,username,email,password_hash,role,class_code,xp_points FROM users ORDER BY id')->fetchAll() === $usersBefore, 'legacy user credentials, XP and labels preserved');
migrationCheck($db->query('SELECT * FROM submissions ORDER BY id')->fetchAll() === $submissionsBefore, 'submission history preserved exactly');
migrationCheck((int)$db->query('SELECT COUNT(*) FROM users WHERE auth_version = 1')->fetchColumn() === 10, 'all old users gain auth_version');
$classes = $db->query('SELECT legacy_class_code,owner_teacher_id,join_code FROM classes ORDER BY legacy_class_code')->fetchAll(PDO::FETCH_UNIQUE);
migrationCheck(count($classes) === 3, 'only explicit nonempty non-ALL classes imported');
migrationCheck((int)$classes['CLEAR']['owner_teacher_id'] === 2, 'single matching teacher owns legacy class');
migrationCheck($classes['AMBIGUOUS']['owner_teacher_id'] === null && $classes['ORPHAN']['owner_teacher_id'] === null, 'ambiguous and orphan legacy classes require admin assignment');
migrationCheck(count(array_filter($classes, static fn(array $c): bool => $c['join_code'] !== null)) === 0, 'legacy codes never become invitation secrets');
migrationCheck((int)$db->query("SELECT COUNT(*) FROM class_members WHERE status = 'active'")->fetchColumn() === 3, 'only eligible legacy students imported as active');
$db->exec("UPDATE class_members SET status = 'removed' WHERE user_id = 6");
$db->exec("UPDATE classes SET owner_teacher_id = 5 WHERE legacy_class_code = 'CLEAR'");
$db->exec('UPDATE users SET auth_version = 7 WHERE id = 6');
$db->exec($m7); $db->exec($m8);
migrationCheck((int)$db->query('SELECT auth_version FROM users WHERE id = 6')->fetchColumn() === 7, 'migration rerun preserves revoked-session version');
migrationCheck($db->query('SELECT status FROM class_members WHERE user_id = 6')->fetchColumn() === 'removed', 'rerun does not reactivate removed membership');
migrationCheck((int)$db->query("SELECT owner_teacher_id FROM classes WHERE legacy_class_code = 'CLEAR'")->fetchColumn() === 5, 'rerun preserves explicit owner reassignment');
$db->exec("INSERT INTO users (username,email,password_hash,full_name) VALUES ('new_student','new_student@example.test','unused-test-hash','New student')");
migrationCheck($db->query("SELECT class_code FROM users WHERE username = 'new_student'")->fetchColumn() === '', 'new accounts no longer inherit old default class');
// v9 must preserve existing recovery tokens and all original table columns/rows.
$db->exec("INSERT INTO password_reset_otps (user_id,otp_hash,expires_at) VALUES (6,'synthetic-hash',DATE_ADD(NOW(),INTERVAL 10 MINUTE))");
$beforeV9 = [];
foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $columns = $db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    $select = implode(',', array_map(static fn($column) => '`' . $column . '`', $columns));
    $order = $table === 'daemon_heartbeat' ? 'name' : 'id';
    $beforeV9[$table] = [$select, $order, $db->query("SELECT $select FROM `$table` ORDER BY `$order`")->fetchAll()];
}
$m9 = file_get_contents($root . '/backend/database/migrations/migrate_v9_profile_audit.sql');
$db->exec($m9);
$db->exec($m9);
foreach ($beforeV9 as $table => [$select, $order, $original]) {
    migrationCheck($db->query("SELECT $select FROM `$table` ORDER BY `$order`")->fetchAll() === $original, 'v9 preserves original rows and columns: ' . $table);
}
$token = $db->query('SELECT purpose,context_hash,auth_version FROM password_reset_otps LIMIT 1')->fetch();
migrationCheck($token['purpose'] === 'password_reset' && $token['context_hash'] === null && $token['auth_version'] === null, 'v9 preserves existing recovery semantics');
migrationCheck((int)$db->query('SELECT COUNT(*) FROM admin_activity_logs')->fetchColumn() === 0, 'audit starts empty without invented past activity');
// v10 is additive and safe to rerun, including after chat state and receipts exist.
$beforeV10 = [];
foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $order = $table === 'daemon_heartbeat' ? 'name' : 'id';
    $beforeV10[$table] = [$order, $db->query("SELECT * FROM `$table` ORDER BY `$order`")->fetchAll()];
}
$m10 = file_get_contents($root . '/backend/database/migrations/migrate_v10_chatbot.sql');
$db->exec($m10);
$session = str_repeat('a', 64);
$draftId = str_repeat('b', 32);
$key = str_repeat('c', 48);
$db->prepare('INSERT INTO chatbot_sessions (id,user_id,auth_version,role,messages) VALUES (?,1,1,\'admin\',?)')->execute([$session, '[{"role":"user","text":"Synthetic migration fixture"}]']);
$db->prepare('INSERT INTO chatbot_creations (session_id,user_id,draft_id,revision,save_key,envelope) VALUES (?,1,?,1,?,?)')->execute([$session, $draftId, $key, '{"status":"saved","problem_id":1}']);
$chatBefore = $db->query('SELECT * FROM chatbot_sessions')->fetchAll();
$receiptsBefore = $db->query('SELECT * FROM chatbot_creations')->fetchAll();
$db->exec($m10);
foreach ($beforeV10 as $table => [$order, $original]) {
    migrationCheck($db->query("SELECT * FROM `$table` ORDER BY `$order`")->fetchAll() === $original, 'v10 preserves complete original table: ' . $table);
}
migrationCheck($db->query('SELECT * FROM chatbot_sessions')->fetchAll() === $chatBefore, 'v10 rerun preserves private chat state');
migrationCheck($db->query('SELECT * FROM chatbot_creations')->fetchAll() === $receiptsBefore, 'v10 rerun preserves save receipts');
echo "Migration checks: $checks passed. Synthetic schema retained: $name.\n";
