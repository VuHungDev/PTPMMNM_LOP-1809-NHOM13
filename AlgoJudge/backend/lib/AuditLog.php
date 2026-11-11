<?php
/** Minimal, append-only administrative events. Never pass request bodies or secrets. */
final class AuditLog
{
    private const EVENTS = [
        'user.create' => ['user', 'Tạo tài khoản'],
        'user.role_change' => ['user', 'Đổi vai trò tài khoản'],
        'user.password_reset' => ['user', 'Đặt lại mật khẩu tài khoản'],
        'user.lock' => ['user', 'Khóa tài khoản'],
        'user.unlock' => ['user', 'Mở khóa tài khoản'],
        'user.delete' => ['user', 'Xóa tài khoản'],
        'class.create' => ['class', 'Tạo lớp học'],
        'class.assign_owner' => ['class', 'Gán giáo viên chủ quản'],
        'class.activate_code' => ['class', 'Kích hoạt mã mời'],
        'class.rotate_code' => ['class', 'Đổi mã mời'],
        'class.add_student' => ['class', 'Thêm học viên vào lớp'],
        'class.approve_member' => ['class', 'Duyệt yêu cầu vào lớp'],
        'class.reject_member' => ['class', 'Từ chối yêu cầu vào lớp'],
        'class.remove_member' => ['class', 'Đưa học viên ra khỏi lớp'],
        'problem.create' => ['problem', 'Tạo bài tập'],
        'problem.update' => ['problem', 'Cập nhật bài tập'],
        'problem.enable' => ['problem', 'Hiện bài tập'],
        'problem.disable' => ['problem', 'Ẩn bài tập'],
        'problem.delete' => ['problem', 'Xóa bài tập'],
        'dataset.create' => ['problem', 'Tạo phiên bản bộ kiểm thử'],
        'judge.rejudge' => ['run', 'Gửi yêu cầu chấm lại'],
        'judge.retry' => ['run', 'Gửi yêu cầu thử chấm lại'],
    ];

    public static function label(string $action): string
    {
        return self::EVENTS[$action][1] ?? 'Thao tác quản trị';
    }

    /** Participant in the mutation's transaction, never starts/commits one itself. */
    public static function record(PDO $db, array $actor, string $action, string $targetType, int $targetId, array $metadata = []): void
    {
        if (($actor['role'] ?? '') !== 'admin') return;
        if (!$db->inTransaction()) throw new LogicException('Audit event requires the mutation transaction.');
        if ((int)($actor['id'] ?? 0) < 1 || $targetId < 1 || !isset(self::EVENTS[$action]) || self::EVENTS[$action][0] !== $targetType) {
            throw new InvalidArgumentException('Invalid administrative event.');
        }
        // A locking read sees current privileges even after earlier REPEATABLE READ queries.
        $check = $db->prepare('SELECT role, is_active, auth_version FROM users WHERE id = ? FOR UPDATE');
        $check->execute([(int)$actor['id']]);
        $current = $check->fetch(PDO::FETCH_ASSOC);
        if (!$current || $current['role'] !== 'admin' || (int)$current['is_active'] !== 1
            || !isset($actor['auth_version']) || (int)$current['auth_version'] !== (int)$actor['auth_version']) {
            throw new DomainException('Quyền quản trị đã thay đổi. Hãy đăng nhập lại.');
        }
        $safe = [];
        foreach ($metadata as $key => $value) {
            if (in_array($key, ['student_id', 'teacher_id', 'version_number', 'submission_id', 'problem_id', 'previous_run_id', 'count'], true)
                && is_int($value) && $value >= 0) {
                $safe[$key] = $value;
            } elseif (in_array($key, ['role', 'previous_role'], true) && in_array($value, ['student', 'teacher', 'admin'], true)) {
                $safe[$key] = $value;
            } elseif ($key === 'source' && in_array($value, ['ui', 'ai', 'api'], true)) {
                $safe[$key] = $value;
            } else {
                // Fail rather than silently writing potentially sensitive/unbounded metadata.
                throw new InvalidArgumentException('Unsupported administrative event metadata.');
            }
        }
        $stmt = $db->prepare('INSERT INTO admin_activity_logs (actor_id, action, target_type, target_id, metadata) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([(int)$actor['id'], $action, $targetType, $targetId, json_encode($safe, JSON_THROW_ON_ERROR)]);
    }

    /** The authenticated profile supplies its own actor ID, never a request parameter. */
    public static function page(PDO $db, int $actorId, int $page, int $perPage = 20): array
    {
        $check = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin' AND is_active = 1");
        $check->execute([$actorId]);
        if (!$check->fetchColumn()) throw new DomainException('Không có quyền xem nhật ký.');
        $perPage = max(1, min(100, $perPage));
        $count = $db->prepare('SELECT COUNT(*) FROM admin_activity_logs WHERE actor_id = ?');
        $count->execute([$actorId]);
        $totalRows = (int)$count->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        $page = max(1, min($totalPages, $page));
        $offset = ($page - 1) * $perPage;
        $stmt = $db->prepare('SELECT id, action, target_type, target_id, metadata, created_at FROM admin_activity_logs WHERE actor_id = ? ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
        $stmt->execute([$actorId]);
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'page' => $page, 'totalPages' => $totalPages, 'totalRows' => $totalRows];
    }
}
