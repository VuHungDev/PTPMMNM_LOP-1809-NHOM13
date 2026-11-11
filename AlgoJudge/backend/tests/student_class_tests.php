<?php
/** CLI-only read API tests. Uses an EXISTING algojudge_test_* schema; fixtures roll back. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$name = getenv('AJ_DB_NAME') ?: '';
$portValue = getenv('AJ_DB_PORT');
$port = is_string($portValue) ? filter_var($portValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) : false;
if (!preg_match('/\Aalgojudge_test_[a-zA-Z0-9_]+\z/', $name) || strlen($name) > 64 || $port === false) {
    fwrite(STDERR, "Refusing to run: set AJ_DB_NAME to an existing algojudge_test_* schema and AJ_DB_PORT to an explicit isolated test port.\n");
    exit(1);
}
// Never load local .env secrets or let bootstrap fall back to working DB settings.
function aj_load_env(string $dir): void {}
putenv('AJ_DB_HOST=' . (getenv('AJ_DB_HOST') ?: '127.0.0.1'));
putenv('AJ_DB_USER=' . (getenv('AJ_DB_USER') ?: 'root'));
putenv('AJ_DB_PASS=' . (getenv('AJ_DB_PASS') ?: ''));
$pdo = new PDO(
    'mysql:host=' . getenv('AJ_DB_HOST') . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4',
    getenv('AJ_DB_USER'),
    getenv('AJ_DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
);
// Connecting with dbname above cannot create a schema; check it before loading production code.
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $name) {
    throw new RuntimeException('Unexpected database; refusing to run.');
}
require_once __DIR__ . '/../lib/ClassManager.php';
if (DB_NAME !== $name || DB_PORT !== $port || $pdo->query('SELECT DATABASE()')->fetchColumn() !== $name) {
    throw new RuntimeException('Bootstrap did not preserve the isolated database.');
}
$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
    fwrite(STDOUT, 'PASS ' . $label . PHP_EOL);
};
$denied = static function (callable $call, string $label) use ($assert): void {
    try {
        $call();
    } catch (DomainException $e) {
        $assert(true, $label);
        return;
    }
    $assert(false, $label);
};
$prefix = 'student_class_' . bin2hex(random_bytes(6));
$pdo->beginTransaction();
try {
    $account = static function (string $suffix, string $role = 'student', int $active = 1, ?string $fullName = null) use ($pdo, $prefix): array {
        $username = $prefix . '_' . $suffix;
        $pdo->prepare('INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$username, $username . '@example.test', '!no-login-test-fixture', $fullName ?? $username, $role, $active]);
        return ['id' => (int)$pdo->lastInsertId(), 'role' => $role, 'auth_version' => 1, 'username' => $username];
    };
    $teacher = $account('teacher', 'teacher');
    $otherTeacher = $account('teacher2', 'teacher');
    $admin = $account('admin', 'admin');
    $viewer = $account('viewer');
    $outsider = $account('outsider');
    $pdo->prepare('INSERT INTO classes (name, join_code, owner_teacher_id, created_by) VALUES (?, ?, ?, ?)')
        ->execute([$prefix . ' class', ClassManager::createJoinCode(), $teacher['id'], $teacher['id']]);
    $classId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO classes (name, owner_teacher_id, created_by) VALUES (?, ?, ?)')
        ->execute([$prefix . ' other class', $otherTeacher['id'], $otherTeacher['id']]);
    $otherClassId = (int)$pdo->lastInsertId();
    $join = static function (array $student, string $status = 'active') use ($pdo, $classId): void {
        $pdo->prepare('INSERT INTO class_members (class_id, user_id, status) VALUES (?, ?, ?)')->execute([$classId, $student['id'], $status]);
    };
    $join($viewer);
    $expectedNames = [$viewer['username']];
    for ($i = 0; $i < 25; $i++) {
        $peer = $account('peer_' . str_pad((string)$i, 2, '0', STR_PAD_LEFT));
        $join($peer);
        $expectedNames[] = $peer['username'];
    }
    sort($expectedNames, SORT_STRING);
    $nonmembers = [];
    foreach (['pending', 'rejected', 'removed'] as $status) {
        $nonmembers[$status] = $account($status);
        $join($nonmembers[$status], $status);
    }
    $locked = $account('locked', 'student', 0);
    $join($locked);
    $expectedNames[] = $locked['username']; // Account locking does not remove membership.
    sort($expectedNames, SORT_STRING);
    $join($otherTeacher); // Stale membership after a role change must never appear as a student.

    $first = ClassManager::getJoinedClass($viewer, $classId, 1, 10);
    $assert($first !== null && $first['total_rows'] === 27 && $first['total_pages'] === 3 && count($first['members']) === 10, 'active membership grants metadata and paginated active-student roster only');
    $assert(array_keys($first['class']) === ['id', 'name', 'owner_name', 'owner_username'], 'metadata allowlist excludes invitation, creator, owner ID and private fields');
    $assert($first['class']['owner_username'] === $teacher['username'], 'teacher display identity is available');
    $seen = [];
    for ($page = 1; $page <= 3; $page++) {
        $result = ClassManager::getJoinedClass($viewer, $classId, $page, 10);
        $assert($result !== null && $result['page'] === $page, 'page ' . $page . ' retains authorized context');
        foreach ($result['members'] as $member) {
            $assert(array_keys($member) === ['full_name', 'username'], 'roster field allowlist excludes email, IDs, status, dates, profiles and submissions');
            $seen[] = $member['username'];
        }
    }
    $assert($seen === $expectedNames, 'stable pages include active locked peers but no duplicates, nonstudents or pending/rejected/removed peers');
    $last = ClassManager::getJoinedClass($viewer, $classId, PHP_INT_MAX, 10);
    $assert($last['page'] === 3 && count($last['members']) === 7, 'oversized page clamps before computing offset');
    $small = ClassManager::getJoinedClass($viewer, $classId, -5, 0);
    $assert($small['page'] === 1 && $small['per_page'] === 1 && count($small['members']) === 1, 'negative page and zero page size are safely bounded');
    $large = ClassManager::getJoinedClass($viewer, $classId, 1, PHP_INT_MAX);
    $assert($large['per_page'] === 100 && count($large['members']) === 27, 'page size is capped at 100');
    $assert(ClassManager::getJoinedClass($viewer, 0) === null && ClassManager::getJoinedClass($viewer, -1) === null, 'invalid class IDs expose no data');
    $assert(ClassManager::getJoinedClass($viewer, PHP_INT_MAX) === null && ClassManager::getJoinedClass($viewer, $otherClassId) === null, 'unknown and unauthorized class IDs both return null');
    $assert(ClassManager::getJoinedClass($outsider, $classId) === null, 'nonmember gets no metadata, count or page');
    foreach ($nonmembers as $status => $student) {
        $assert(ClassManager::getJoinedClass($student, $classId, 2) === null, $status . ' membership gets no metadata, count or later page');
    }
    $assert(ClassManager::getJoinedClass($locked, $classId) === null, 'disabled active member denied by current database account state');
    $assert(ClassManager::getJoinedClass(array_merge($viewer, ['auth_version' => 999]), $classId) === null, 'stale session version denied');
    $assert(ClassManager::getJoinedClass(array_merge($otherTeacher, ['role' => 'student']), $classId) === null, 'cached student role cannot bypass current teacher role');
    $denied(fn() => ClassManager::getJoinedClass($teacher, $classId), 'teacher cannot use student read helper');
    $denied(fn() => ClassManager::getJoinedClass($admin, $classId), 'admin cannot use student read helper');
    $denied(fn() => ClassManager::getManagedClass($viewer, $classId), 'student remains denied by staff read helper');
    $assert(ClassManager::getManagedClass($teacher, $classId) !== null && ClassManager::getManagedClass($otherTeacher, $classId) === null && ClassManager::getManagedClass($admin, $classId) !== null, 'staff ownership and admin access remain unchanged');

    $pdo->prepare('UPDATE users SET full_name = ? WHERE id = ?')->execute(['', $teacher['id']]);
    $emptyName = ClassManager::getJoinedClass($viewer, $classId);
    $assert($emptyName['class']['owner_name'] === '' && $emptyName['class']['owner_username'] === $teacher['username'], 'empty teacher name keeps username fallback available');
    $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$teacher['id']]);
    $inactiveOwner = ClassManager::getJoinedClass($viewer, $classId);
    $assert($inactiveOwner['class']['owner_name'] === null && $inactiveOwner['class']['owner_username'] === null, 'inactive owner uses empty-owner state without denying active members');
    $pdo->prepare('UPDATE classes SET owner_teacher_id = NULL WHERE id = ?')->execute([$classId]);
    $unowned = ClassManager::getJoinedClass($viewer, $classId);
    $assert($unowned !== null && $unowned['class']['owner_name'] === null && $unowned['class']['owner_username'] === null, 'unowned class remains readable with explicit empty teacher identity');

    $pdo->prepare("UPDATE class_members SET status = 'removed' WHERE class_id = ? AND user_id = ?")->execute([$classId, $viewer['id']]);
    $assert(ClassManager::getJoinedClass($viewer, $classId, 1) === null && ClassManager::getJoinedClass($viewer, $classId, 2) === null, 'removal immediately revokes metadata, count and every roster page');
    $pdo->prepare("UPDATE class_members SET status = 'active' WHERE class_id = ? AND user_id = ?")->execute([$classId, $viewer['id']]);
    $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$viewer['id']]);
    $assert(ClassManager::getJoinedClass($viewer, $classId) === null, 'account locking immediately revokes class reads');
    $pdo->prepare("UPDATE users SET is_active = 1, role = 'teacher' WHERE id = ?")->execute([$viewer['id']]);
    $assert(ClassManager::getJoinedClass($viewer, $classId) === null, 'role change immediately revokes cached student reads');
    $pdo->prepare("UPDATE users SET role = 'student', auth_version = auth_version + 1 WHERE id = ?")->execute([$viewer['id']]);
    $assert(ClassManager::getJoinedClass($viewer, $classId) === null, 'session revocation immediately denies cached reads');
    fwrite(STDOUT, "Student class checks: {$checks} passed; test fixtures rolled back.\n");
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
