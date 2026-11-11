<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/AuditLog.php';

final class ClassManager
{
    public static function getTeacherClasses(array $user): array
    {
        global $pdo;
        self::requireRole($user, ['teacher', 'admin']);
        $sql = 'SELECT c.*, u.full_name AS owner_name FROM classes c LEFT JOIN users u ON u.id = c.owner_teacher_id';
        $params = [];
        if ($user['role'] === 'teacher') {
            $sql .= ' WHERE c.owner_teacher_id = ?';
            $params[] = (int)$user['id'];
        }
        $stmt = $pdo->prepare($sql . ' ORDER BY c.name, c.id');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getManagedClass(array $user, int $classId): ?array
    {
        global $pdo;
        self::requireRole($user, ['teacher', 'admin']);
        $sql = 'SELECT c.*, u.full_name AS owner_name, u.role AS owner_role, u.is_active AS owner_active FROM classes c LEFT JOIN users u ON u.id = c.owner_teacher_id WHERE c.id = ?';
        $params = [$classId];
        if ($user['role'] === 'teacher') {
            $sql .= ' AND c.owner_teacher_id = ?';
            $params[] = (int)$user['id'];
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    /**
     * Read-only student view. Do not reuse the staff reader: it includes invitations.
     * Every query checks live membership/account access, including count and paging.
     * null deliberately covers both an unknown class and a class the student cannot see.
     */
    public static function getJoinedClass(array $user, int $classId, int $page = 1, int $perPage = 20): ?array
    {
        global $pdo;
        self::requireRole($user, ['student']);
        if ($classId < 1) return null;
        $perPage = max(1, min(100, $perPage));
        $accessSql = "EXISTS (SELECT 1 FROM class_members access_cm
            JOIN users viewer ON viewer.id = access_cm.user_id
            WHERE access_cm.class_id = c.id AND access_cm.user_id = ?
              AND access_cm.status = 'active' AND viewer.role = 'student' AND viewer.is_active = 1";
        $params = [$classId, (int)$user['id']];
        if (isset($user['auth_version'])) {
            $accessSql .= ' AND viewer.auth_version = ?';
            $params[] = (int)$user['auth_version'];
        }
        $accessSql .= ')';
        $rosterSql = " FROM class_members cm JOIN users student ON student.id = cm.user_id
            WHERE cm.class_id = c.id AND cm.status = 'active'
              AND student.role = 'student'";
        // The outer class scope distinguishes denied access from an empty count.
        $stmt = $pdo->prepare('SELECT (SELECT COUNT(*)' . $rosterSql . ')
            FROM classes c WHERE c.id = ? AND ' . $accessSql);
        $stmt->execute($params);
        $count = $stmt->fetchColumn();
        if ($count === false) return null;
        $totalRows = (int)$count;
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare("SELECT student.full_name, student.username
            FROM classes c JOIN class_members cm ON cm.class_id = c.id
            JOIN users student ON student.id = cm.user_id
            WHERE c.id = ? AND {$accessSql} AND cm.status = 'active'
              AND student.role = 'student'
            ORDER BY student.username, student.id LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);
        $members = $stmt->fetchAll();
        // Read metadata last, rechecking access if removal happened while paging.
        $stmt = $pdo->prepare("SELECT c.id, c.name, owner.full_name AS owner_name, owner.username AS owner_username
            FROM classes c LEFT JOIN users owner ON owner.id = c.owner_teacher_id
              AND owner.role = 'teacher' AND owner.is_active = 1
            WHERE c.id = ? AND {$accessSql}");
        $stmt->execute($params);
        $class = $stmt->fetch();
        if (!$class) return null;
        return [
            'class' => $class,
            'members' => $members,
            'page' => $page,
            'per_page' => $perPage,
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
        ];
    }

    public static function createJoinCode(): string
    {
        return strtoupper(bin2hex(random_bytes(8)));
    }

    public static function createClass(array $actor, string $name, int $ownerId): int
    {
        global $pdo;
        self::requireRole($actor, ['teacher', 'admin']);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new DomainException('Tên lớp phải có từ 1 đến 100 ký tự.');
        }
        if ($actor['role'] === 'teacher') $ownerId = (int)$actor['id'];

        return self::transaction(function () use ($pdo, $actor, $name, $ownerId): int {
            $users = self::lockUsers($actor, [$ownerId]);
            self::requireTeacher($users[$ownerId] ?? null);
            $pdo->prepare('INSERT INTO classes (name, join_code, owner_teacher_id, created_by) VALUES (?, ?, ?, ?)')
                ->execute([$name, self::createJoinCode(), $ownerId, (int)$actor['id']]);
            $classId = (int)$pdo->lastInsertId();
            AuditLog::record($pdo, $actor, 'class.create', 'class', $classId, ['teacher_id' => $ownerId, 'source' => 'ui']);
            return $classId;
        });
    }

    public static function assignOwner(array $actor, int $classId, int $ownerId): void
    {
        global $pdo;
        self::requireRole($actor, ['admin']);
        self::transaction(function () use ($pdo, $actor, $classId, $ownerId): void {
            $users = self::lockUsers($actor, [$ownerId]);
            self::requireTeacher($users[$ownerId] ?? null);
            $class = self::lockManagedClass($actor, $classId);
            // Existence is checked above: MySQL rowCount() is zero for an unchanged owner.
            $code = $class['join_code'] ?: self::createJoinCode();
            if ((int)$class['owner_teacher_id'] === $ownerId && $class['join_code']) return;
            $pdo->prepare('UPDATE classes SET owner_teacher_id = ?, join_code = ? WHERE id = ?')
                ->execute([$ownerId, $code, $classId]);
            AuditLog::record($pdo, $actor, 'class.assign_owner', 'class', $classId, ['teacher_id' => $ownerId, 'source' => 'ui']);
        });
    }

    public static function changeJoinCode(array $actor, int $classId, bool $activateOnly = false): void
    {
        global $pdo;
        self::requireRole($actor, ['teacher', 'admin']);
        self::transaction(function () use ($pdo, $actor, $classId, $activateOnly): void {
            self::lockUsers($actor);
            $class = self::lockManagedClass($actor, $classId);
            self::requireClassOwner($class);
            if ($activateOnly && !empty($class['join_code'])) {
                throw new DomainException('Lớp đã có mã mời. Hãy dùng “Đổi mã mời” nếu cần thay mã.');
            }
            $pdo->prepare('UPDATE classes SET join_code = ? WHERE id = ?')
                ->execute([self::createJoinCode(), $classId]);
            AuditLog::record($pdo, $actor, $activateOnly ? 'class.activate_code' : 'class.rotate_code', 'class', $classId, ['source' => 'ui']);
        });
    }

    public static function requestJoin(array $actor, string $code): string
    {
        global $pdo;
        self::requireRole($actor, ['student']);
        $code = strtoupper(trim($code));
        if (!preg_match('/\A[A-F0-9]{16}\z/', $code)) {
            throw new DomainException('Mã tham gia không hợp lệ. Mã gồm 16 ký tự do giáo viên cung cấp.');
        }
        return self::transaction(function () use ($pdo, $actor, $code): string {
            self::lockUsers($actor);
            $stmt = $pdo->prepare('SELECT * FROM classes WHERE join_code = ? FOR UPDATE');
            $stmt->execute([$code]);
            $class = $stmt->fetch();
            if (!$class) throw new DomainException('Mã tham gia không hợp lệ hoặc đã được thay đổi.');
            self::requireClassOwner($class);
            $classId = (int)$class['id'];
            $studentId = (int)$actor['id'];
            $stmt = $pdo->prepare('SELECT status FROM class_members WHERE class_id = ? AND user_id = ? FOR UPDATE');
            $stmt->execute([$classId, $studentId]);
            $status = $stmt->fetchColumn();
            if ($status === 'active') return 'Bạn đã là thành viên của lớp này.';
            if ($status === 'pending') return 'Yêu cầu của bạn đang chờ giáo viên duyệt.';
            if ($status === false) {
                $pdo->prepare("INSERT INTO class_members (class_id, user_id, status) VALUES (?, ?, 'pending')")
                    ->execute([$classId, $studentId]);
            } else {
                $pdo->prepare("UPDATE class_members SET status = 'pending', requested_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE class_id = ? AND user_id = ?")
                    ->execute([$classId, $studentId]);
            }
            return 'Đã gửi yêu cầu tham gia lớp. Hãy đợi giáo viên duyệt.';
        });
    }

    public static function addStudent(array $actor, int $classId, string $identifier): string
    {
        global $pdo;
        self::requireRole($actor, ['teacher', 'admin']);
        $identifier = trim($identifier);
        if ($identifier === '' || mb_strlen($identifier) > 100) {
            throw new DomainException('Nhập username hoặc email của học viên đã đăng ký (tối đa 100 ký tự).');
        }
        // Exact account lookup only; teachers cannot create accounts or edit profiles here.
        $stmt = $pdo->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND role = 'student' LIMIT 2");
        $stmt->execute([$identifier, $identifier]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) !== 1) {
            throw new DomainException('Không tìm thấy duy nhất một học viên đã đăng ký. Hãy kiểm tra username hoặc email.');
        }
        $studentId = (int)$ids[0];
        return self::transaction(function () use ($pdo, $actor, $classId, $studentId, $identifier): string {
            $users = self::lockUsers($actor, [$studentId]);
            self::requireStudent($users[$studentId] ?? null);
            self::lockManagedClass($actor, $classId);
            $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND (username = ? OR email = ?)');
            $stmt->execute([$studentId, $identifier, $identifier]);
            if (!$stmt->fetchColumn()) throw new DomainException('Thông tin tài khoản vừa thay đổi. Hãy kiểm tra và thử lại.');
            $stmt = $pdo->prepare('SELECT status FROM class_members WHERE class_id = ? AND user_id = ? FOR UPDATE');
            $stmt->execute([$classId, $studentId]);
            $status = $stmt->fetchColumn();
            if ($status === 'active') return 'Học viên đã là thành viên của lớp này.';
            if ($status === false) {
                $pdo->prepare("INSERT INTO class_members (class_id, user_id, status) VALUES (?, ?, 'active')")
                    ->execute([$classId, $studentId]);
            } else {
                $pdo->prepare("UPDATE class_members SET status = 'active', updated_at = CURRENT_TIMESTAMP WHERE class_id = ? AND user_id = ?")
                    ->execute([$classId, $studentId]);
            }
            AuditLog::record($pdo, $actor, 'class.add_student', 'class', $classId, ['student_id' => $studentId, 'source' => 'ui']);
            return 'Đã thêm học viên vào lớp (không cần chờ duyệt).';
        });
    }

    public static function updateMember(array $actor, int $classId, int $studentId, string $action): void
    {
        global $pdo;
        self::requireRole($actor, ['teacher', 'admin']);
        $transitions = [
            'approve_member' => ['pending', 'active'],
            'reject_member' => ['pending', 'rejected'],
            'remove_member' => ['active', 'removed'],
        ];
        if (!isset($transitions[$action]) || $studentId < 1) {
            throw new DomainException('Thao tác thành viên không hợp lệ.');
        }
        [$before, $after] = $transitions[$action];
        self::transaction(function () use ($pdo, $actor, $classId, $studentId, $action, $before, $after): void {
            $users = self::lockUsers($actor, [$studentId]);
            self::lockManagedClass($actor, $classId);
            if ($action === 'approve_member') self::requireStudent($users[$studentId] ?? null);
            $stmt = $pdo->prepare('UPDATE class_members SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE class_id = ? AND user_id = ? AND status = ?');
            $stmt->execute([$after, $classId, $studentId, $before]);
            if (!$stmt->rowCount()) {
                throw new DomainException('Thành viên không còn ở trạng thái phù hợp. Danh sách đã được cập nhật; hãy kiểm tra lại.');
            }
            AuditLog::record($pdo, $actor, 'class.' . $action, 'class', $classId, ['student_id' => $studentId, 'source' => 'ui']);
            // Never delete a membership or submissions: rejected/removed rows retain history.
        });
    }

    private static function requireRole(array $actor, array $roles): void
    {
        if ((int)($actor['id'] ?? 0) < 1 || !in_array($actor['role'] ?? '', $roles, true)) {
            throw new DomainException('Bạn không có quyền thực hiện thao tác này.');
        }
    }

    private static function transaction(callable $operation)
    {
        global $pdo;
        $pdo->beginTransaction();
        try {
            $result = $operation();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function lockUsers(array $actor, array $otherIds = []): array
    {
        global $pdo;
        $ids = array_unique(array_merge([(int)$actor['id']], array_map('intval', $otherIds)));
        sort($ids, SORT_NUMERIC);
        $stmt = $pdo->prepare('SELECT id, role, is_active, auth_version FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id FOR UPDATE');
        $stmt->execute($ids);
        $users = [];
        foreach ($stmt->fetchAll() as $row) $users[(int)$row['id']] = $row;
        $current = $users[(int)$actor['id']] ?? null;
        if (!$current || (int)$current['is_active'] !== 1 || $current['role'] !== $actor['role']
            || (isset($actor['auth_version']) && (int)$actor['auth_version'] !== (int)$current['auth_version'])) {
            throw new DomainException('Quyền tài khoản đã thay đổi. Hãy đăng nhập lại.');
        }
        // Lock users before classes/memberships, also used by admin role changes.
        return $users;
    }

    private static function lockManagedClass(array $actor, int $classId): array
    {
        global $pdo;
        $stmt = $pdo->prepare('SELECT * FROM classes WHERE id = ? FOR UPDATE');
        $stmt->execute([$classId]);
        $class = $stmt->fetch();
        if (!$class || ($actor['role'] !== 'admin' && (int)$class['owner_teacher_id'] !== (int)$actor['id'])) {
            throw new DomainException('Lớp không tồn tại hoặc bạn không quản lý lớp này.');
        }
        return $class;
    }

    private static function requireTeacher(?array $user): void
    {
        if (!$user || $user['role'] !== 'teacher' || (int)$user['is_active'] !== 1) {
            throw new DomainException('Hãy chọn giáo viên chủ quản hợp lệ, không bị khóa.');
        }
    }

    private static function requireStudent(?array $user): void
    {
        if (!$user || $user['role'] !== 'student' || (int)$user['is_active'] !== 1) {
            throw new DomainException('Chỉ có thể thêm hoặc duyệt tài khoản học viên không bị khóa.');
        }
    }

    private static function requireClassOwner(array $class): void
    {
        global $pdo;
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher' AND is_active = 1");
        $stmt->execute([(int)$class['owner_teacher_id']]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('Lớp chưa có giáo viên chủ quản đang hoạt động. Hãy liên hệ quản trị viên.');
        }
    }
}
