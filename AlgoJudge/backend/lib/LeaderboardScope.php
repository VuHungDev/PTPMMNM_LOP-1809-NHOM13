<?php
// Aggregate leaderboard visibility only. Never reuse this exception for source,
// submission details, class rosters, progress reports or other teacher resources.
require_once __DIR__ . '/Auth.php';

final class LeaderboardScope
{
    public static function normalizeScope(array $viewer, $value): string
    {
        return $viewer['role'] === 'teacher' && is_string($value) && $value === 'system'
            ? 'system'
            : 'managed';
    }

    public static function resolve(PDO $pdo, array $viewer, array $query): array
    {
        $scope = self::normalizeScope($viewer, $query['scope'] ?? null);
        $classId = filter_var($query['class_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $teacherSystem = $viewer['role'] === 'teacher' && $scope === 'system';

        if ($classId > 0) {
            if ($viewer['role'] === 'teacher' && !$teacherSystem && !Auth::teacherOwnsClass((int)$viewer['id'], $classId)) {
                throw new DomainException('403 Forbidden — Bạn không quản lý lớp này.', 403);
            }
            if ($viewer['role'] === 'student') {
                $membership = $pdo->prepare("SELECT 1 FROM class_members WHERE user_id = ? AND class_id = ? AND status = 'active'");
                $membership->execute([(int)$viewer['id'], $classId]);
                if (!$membership->fetchColumn()) {
                    throw new DomainException('Bạn chưa là thành viên của lớp này.', 403);
                }
            }
            if ($teacherSystem) {
                $existing = $pdo->prepare('SELECT 1 FROM classes WHERE id = ?');
                $existing->execute([$classId]);
                if (!$existing->fetchColumn()) {
                    throw new DomainException('Không tìm thấy lớp học.', 404);
                }
            }
        }

        $where = ["u.role = 'student'"];
        $params = [];
        if ($viewer['role'] === 'teacher' && !$teacherSystem) {
            if ($classId > 0) {
                $where[] = "EXISTS (SELECT 1 FROM class_members cm JOIN classes c ON c.id = cm.class_id WHERE cm.user_id = u.id AND cm.class_id = ? AND cm.status = 'active' AND c.owner_teacher_id = ?)";
                $params = [$classId, (int)$viewer['id']];
            } else {
                $where[] = Auth::teacherStudentScope('u');
                $params[] = (int)$viewer['id'];
            }
        } elseif ($classId > 0) {
            $where[] = "EXISTS (SELECT 1 FROM class_members cm WHERE cm.user_id = u.id AND cm.class_id = ? AND cm.status = 'active')";
            $params[] = $classId;
        }

        // The system leaderboard alone may label every active membership,
        // including classes managed by other teachers or with no owner.
        $classNamesSql = $teacherSystem
            ? "(SELECT GROUP_CONCAT(DISTINCT cn.name ORDER BY cn.name SEPARATOR ', ') FROM class_members label_cm JOIN classes cn ON cn.id = label_cm.class_id WHERE label_cm.user_id = u.id AND label_cm.status = 'active')"
            : Auth::classNamesSql($viewer);

        return [
            'scope' => $scope,
            'class_id' => $classId,
            'where_sql' => 'WHERE ' . implode(' AND ', $where),
            'params' => $params,
            'class_names_sql' => $classNamesSql,
        ];
    }
}
