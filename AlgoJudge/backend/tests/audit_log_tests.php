<?php
/** CLI-only integration checks, exclusively against a pre-created disposable database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$name = getenv('AJ_DB_NAME') ?: '';
$port = filter_var(getenv('AJ_DB_PORT'), FILTER_VALIDATE_INT);
if (!preg_match('/^algojudge_test_[a-zA-Z0-9_]+$/D', $name) || !$port) exit("Refusing non-test database.\n");
function aj_load_env(string $directory): void {} // Test credentials are explicit; never load .env.
require_once dirname(__DIR__) . '/lib/ClassManager.php';
$prefix = 'audit_' . bin2hex(random_bytes(5));
$checks = 0;
function auditCheck(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException('FAIL ' . $label); $checks++; echo "PASS $label\n"; }
$accounts = [];
foreach (['admin','teacher','student','admin'] as $i => $role) {
    $pdo->prepare('INSERT INTO users (username,email,password_hash,full_name,role) VALUES (?,?,?,?,?)')
        ->execute([$prefix.$i, $prefix.$i.'@example.test', 'unused-test-hash', 'Audit test '.$i, $role]);
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id=?'); $stmt->execute([(int)$pdo->lastInsertId()]); $accounts[] = $stmt->fetch();
}
[$admin,$teacher,$student,$otherAdmin] = $accounts;
$events = static function () use ($pdo,$admin): int { $s=$pdo->prepare('SELECT COUNT(*) FROM admin_activity_logs WHERE actor_id=?'); $s->execute([$admin['id']]); return (int)$s->fetchColumn(); };
$classId = ClassManager::createClass($admin, $prefix, (int)$teacher['id']);
auditCheck($events() === 1, 'class creation and event committed together');
ClassManager::assignOwner($admin, $classId, (int)$teacher['id']);
auditCheck($events() === 1, 'same owner no-op has no event');
ClassManager::addStudent($admin,$classId,$student['username']);
ClassManager::addStudent($admin,$classId,$student['username']);
auditCheck($events() === 2, 'duplicate enrollment does not duplicate event');
ClassManager::updateMember($admin,$classId,(int)$student['id'],'remove_member');
auditCheck($events() === 3, 'member removal logged');
try { ClassManager::updateMember($admin,$classId,(int)$student['id'],'remove_member'); } catch (DomainException $e) {}
auditCheck($events() === 3, 'rejected transition not logged');
$code=$pdo->query('SELECT join_code FROM classes WHERE id='.$classId)->fetchColumn();
ClassManager::requestJoin($student,$code);
ClassManager::updateMember($admin,$classId,(int)$student['id'],'reject_member');
ClassManager::requestJoin($student,$code);
ClassManager::updateMember($admin,$classId,(int)$student['id'],'approve_member');
ClassManager::changeJoinCode($admin,$classId);
auditCheck($events() === 6, 'request decisions and code rotation logged');
ClassManager::changeJoinCode($teacher,$classId);
auditCheck($events() === 6, 'teacher action not attributed to admin');
$metadata=$pdo->query('SELECT metadata FROM admin_activity_logs WHERE actor_id='.(int)$admin['id'])->fetchAll(PDO::FETCH_COLUMN);
auditCheck(strpos(implode('', $metadata),$code) === false && strpos(implode('', $metadata),'@') === false, 'metadata contains neither invitations nor email');
$pdo->beginTransaction();
$pdo->prepare('UPDATE classes SET name=? WHERE id=?')->execute(['Temporary',$classId]);
AuditLog::record($pdo,$admin,'class.rotate_code','class',$classId);
$pdo->rollBack();
auditCheck($events()===6 && $pdo->query('SELECT name FROM classes WHERE id='.$classId)->fetchColumn()===$prefix,'rollback removes event and mutation');
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE classes SET name=? WHERE id=?')->execute(['Invalid',$classId]);
    AuditLog::record($pdo,$admin,'class.rotate_code','class',$classId,['password'=>'must-not-log']);
    throw new RuntimeException('Metadata validation failed open.');
} catch (InvalidArgumentException $e) { $pdo->rollBack(); }
auditCheck($events()===6 && $pdo->query('SELECT name FROM classes WHERE id='.$classId)->fetchColumn()===$prefix,'invalid audit metadata rolls back associated mutation');
$refused=false; try { AuditLog::record($pdo,$admin,'class.create','class',$classId); } catch (LogicException $e) { $refused=true; }
auditCheck($refused,'events cannot autocommit outside mutation');
$page=AuditLog::page($pdo,(int)$admin['id'],2,2);
auditCheck($page['page']===2 && count($page['rows'])===2 && $page['totalRows']===6,'admin events paginate deterministically');
auditCheck(AuditLog::page($pdo,(int)$otherAdmin['id'],1)['totalRows']===0,'separate admin history isolated');
$refused=false; try { AuditLog::page($pdo,(int)$student['id'],1); } catch (DomainException $e) { $refused=true; }
auditCheck($refused,'student cannot read audit history');
// Retain these test-only records as evidence; never clean up an existing working database.
echo "Audit checks: $checks passed.\n";
