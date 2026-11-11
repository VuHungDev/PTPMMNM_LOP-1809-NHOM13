<?php
/** Isolated integration tests: creates ONLY a new algojudge_test_* schema. Never seeds a working DB. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start(); // Auth::logout/session cookies remain valid while assertions print.
$name = getenv('AJ_DB_NAME') ?: '';
$port = (int)(getenv('AJ_DB_PORT') ?: 0);
if (!preg_match('/^algojudge_test_[a-zA-Z0-9_]+$/D', $name) || !$port) {
    fwrite(STDERR, "Set AJ_DB_NAME=algojudge_test_<unique> and AJ_DB_PORT to an isolated MariaDB.\n"); exit(1);
}
$root = dirname(__DIR__, 2);
$db = new PDO('mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4', getenv('AJ_DB_USER') ?: 'root', getenv('AJ_DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$exists = $db->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
$exists->execute([$name]);
if ($exists->fetchColumn()) { fwrite(STDERR, "Refusing to overwrite existing schema $name. Use a new name.\n"); exit(1); }
$sql = file_get_contents($root . '/backend/database/schema.sql');
$sql = str_replace(['CREATE DATABASE IF NOT EXISTS algojudge ', 'USE algojudge;'], ["CREATE DATABASE `$name` ", "USE `$name`;"], $sql);
$db->exec($sql);
require_once $root . '/backend/lib/Auth.php';
require_once $root . '/backend/lib/ClassManager.php';
require_once $root . '/backend/lib/PasswordReset.php';
$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $label); $checks++; echo "PASS $label\n"; }
function denied(callable $call, string $label): void { try { $call(); } catch (DomainException | RuntimeException $e) { check(true, $label); return; } check(false, $label); }
function account(string $username, string $role, string $legacy = '', string $name = ''): array {
    global $pdo;
    $pdo->prepare('INSERT INTO users (username, email, password_hash, full_name, role, class_code) VALUES (?, ?, ?, ?, ?, ?)')->execute([$username, $username . '@example.test', password_hash('Test-pass-2026', PASSWORD_DEFAULT), $name ?: $username, $role, $legacy]);
    $s = $pdo->prepare('SELECT * FROM users WHERE id = ?'); $s->execute([(int)$pdo->lastInsertId()]); return $s->fetch();
}
$admin = account('test_admin', 'admin');
$t1 = account('test_teacher1', 'teacher', 'LEGACY-A');
$t2 = account('test_teacher2', 'teacher', 'ALL');
$s1 = account('test_student1', 'student', 'LEGACY-A', '=SUM(1+1)');
$s2 = account('test_student2', 'student', 'LEGACY-B');
$pending = account('test_pending', 'student');
$blocked = account('test_locked', 'student');
$pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$blocked['id']]);
$m7 = file_get_contents($root . '/backend/database/migrations/migrate_v7_password_reset.sql');
$m8 = file_get_contents($root . '/backend/database/migrations/migrate_v8_classes.sql');
$pdo->exec($m7); $pdo->exec($m8);
$legacy = $pdo->query("SELECT * FROM classes WHERE legacy_class_code = 'LEGACY-A'")->fetch();
$unowned = $pdo->query("SELECT * FROM classes WHERE legacy_class_code = 'LEGACY-B'")->fetch();
check((int)$legacy['owner_teacher_id'] === (int)$t1['id'] && $legacy['join_code'] === null, 'legacy class gets only unambiguous owner and no predictable code');
check($unowned['owner_teacher_id'] === null, 'ALL does not become blanket teacher access');
check(Auth::canViewSubmission($t1, $s1) && !Auth::canViewSubmission($t2, $s1), 'legacy membership ACL');
ClassManager::changeJoinCode($t1, (int)$legacy['id'], true);
ClassManager::assignOwner($admin, (int)$unowned['id'], (int)$t2['id']);
ClassManager::assignOwner($admin, (int)$unowned['id'], (int)$t2['id']);
check(true, 'assign same owner is idempotent');
$c1 = ClassManager::createClass($t1, 'Test class one', (int)$t1['id']);
$c2 = ClassManager::createClass($t2, 'Test class two', (int)$t2['id']);
$code = $pdo->query('SELECT join_code FROM classes WHERE id = ' . $c1)->fetchColumn();
ClassManager::requestJoin($pending, $code);
check(!Auth::canViewSubmission($t1, $pending), 'pending membership grants no access');
ClassManager::updateMember($t1, $c1, (int)$pending['id'], 'approve_member');
check(Auth::canViewSubmission($t1, $pending), 'approval grants teacher access');
ClassManager::addStudent($t1, $c1, $s1['username']);
ClassManager::addStudent($t2, $c2, $s1['email']);
check(Auth::canViewSubmission($t1, $s1) && Auth::canViewSubmission($t2, $s1), 'student belongs to multiple owned classes');
$q = $pdo->prepare('SELECT COUNT(*) FROM users u WHERE ' . Auth::teacherStudentScope()); $q->execute([$t1['id']]);
check((int)$q->fetchColumn() === 2, 'EXISTS scope avoids duplicate students');
denied(fn() => ClassManager::addStudent($t2, $c1, $s2['username']), 'foreign teacher enrollment denied');
denied(fn() => ClassManager::addStudent($t1, $c1, $admin['username']), 'nonstudent enrollment denied');
ClassManager::updateMember($t1, $c1, (int)$pending['id'], 'remove_member');
check(!Auth::canViewSubmission($t1, $pending), 'removed membership revokes access');
ClassManager::changeJoinCode($t1, $c1);
denied(fn() => ClassManager::requestJoin($pending, $code), 'rotated invitation rejects old code');
$pdo->exec($m7); $pdo->exec($m8);
check((int)$pdo->query('SELECT COUNT(*) FROM classes')->fetchColumn() === 4, 'migrations rerun without duplicate classes');
$new = account('test_fresh', 'student');
$pdo->exec($m8);
$q = $pdo->prepare('SELECT COUNT(*) FROM class_members WHERE user_id = ?'); $q->execute([$new['id']]);
check((int)$q->fetchColumn() === 0, 'new registration is not backfilled into legacy class');
$deliveries = [];
$reset = new PasswordReset($pdo, str_repeat('test-key-', 8), function ($email, $code) use (&$deliveries) { $deliveries[] = [$email, $code]; });
$id = $reset->request($s1['email'], '127.1.1.1');
check($id !== null && count($deliveries) === 1, 'eligible student OTP issued');
$otp = $deliveries[0][1];
check(!$reset->verify($id, $s1['email'], $otp === '111111' ? '222222' : '111111', 'New-pass-2026'), 'wrong OTP rejected');
check($reset->verify($id, $s1['email'], $otp, 'New-pass-2026'), 'correct OTP resets password');
check(!$reset->verify($id, $s1['email'], $otp, 'Other-pass-2026'), 'OTP is single-use');
$q = $pdo->prepare('SELECT password_hash, auth_version FROM users WHERE id = ?'); $q->execute([$s1['id']]); $changed = $q->fetch();
check(password_verify('New-pass-2026', $changed['password_hash']) && (int)$changed['auth_version'] === 2, 'password hash and session version updated atomically');
$_SESSION['user'] = $s1;
check(Auth::user() === null, 'old session revoked by reset');
// No session/header calls below this point.
check($reset->request($admin['email'], '127.1.1.2') === null, 'admin cannot request reset');
check($reset->request($blocked['email'], '127.1.1.3') === null, 'locked account cannot request reset');
check($reset->request('unknown@example.test', '127.1.1.4') === null, 'unknown account gets no token');
$id2 = $reset->request($s2['email'], '127.1.1.5'); $otp2 = end($deliveries)[1];
$wrong = $otp2 === '111111' ? '222222' : '111111';
for ($i = 0; $i < 5; $i++) check(!$reset->verify($id2, $s2['email'], $wrong, 'New-pass-2026'), 'wrong attempt ' . ($i + 1));
check(!$reset->verify($id2, $s2['email'], $otp2, 'New-pass-2026'), 'five attempts exhaust OTP');
check($reset->request($s2['email'], '127.1.1.5') === null, 'resend cooldown');
$id3 = $reset->request($t1['email'], '127.1.1.6'); $otp3 = end($deliveries)[1];
$pdo->prepare('UPDATE password_reset_otps SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?')->execute([$id3]);
check(!$reset->verify($id3, $t1['email'], $otp3, 'New-pass-2026'), 'database-clock expiry');
$badMail = new PasswordReset($pdo, str_repeat('test-key-', 8), function () { throw new RuntimeException('Simulated SMTP failure'); });
denied(fn() => $badMail->request($new['email'], '127.1.1.7'), 'mail failure propagates internally');
$q = $pdo->prepare('SELECT COUNT(*) FROM password_reset_otps WHERE user_id = ?'); $q->execute([$new['id']]); check((int)$q->fetchColumn() === 0, 'failed send rolls token back');
for ($i = 0; $i < 4; $i++) $reset->request('absent@example.test', '127.1.1.8');
$digest = hash_hmac('sha256', 'email:absent@example.test', str_repeat('test-key-', 8));
$q = $pdo->prepare('SELECT COUNT(*) FROM password_reset_requests WHERE email_digest = ?'); $q->execute([$digest]); check((int)$q->fetchColumn() === 3, 'email rate limit persists across attempts');
// Fixtures for HTTP pagination and exports, still in this newly-created test schema.
for ($i = 1; $i <= 35; $i++) {
    $pdo->prepare("INSERT INTO problems (slug,title,topic,difficulty,created_by) VALUES (?,?,'Kiểm thử','Easy',?)")->execute(['test-' . $i, 'Test problem ' . $i, $t1['id']]); $pid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO problem_versions (problem_id,version_number,description,input_desc,output_desc,sample_input,sample_output,created_by) VALUES (?,1,'Test','Test','Test','1','1',?)")->execute([$pid, $t1['id']]); $vid = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE problems SET current_version_id = ? WHERE id = ?')->execute([$vid, $pid]);
    $owner = $i % 2 ? $s1['id'] : $s2['id'];
    $pdo->prepare("INSERT INTO submissions (idempotency_key,user_id,problem_id,problem_version_id,language,source_code,final_verdict) VALUES (?,?,?,?,'python','print(1)','AC')")->execute(['upgrade-' . $i, $owner, $pid, $vid]); $sid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO judging_runs (submission_id,problem_version_id,triggered_by,status) VALUES (?,?,?,'SYS_ERROR')")->execute([$sid, $vid, $t1['id']]); $rid = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE submissions SET latest_run_id = ? WHERE id = ?')->execute([$rid, $sid]);
}
for ($i = 0; $i < 21; $i++) account('test_extra_' . $i, 'student');
echo "Upgrade checks: $checks passed. Test schema retained for HTTP checks: $name (port $port).\n";
