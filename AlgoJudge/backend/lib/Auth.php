<?php
// backend/lib/Auth.php — Nhóm tự viết: kiểm soát đăng nhập và phân quyền
// 3 vai trò: admin (toàn quyền) / teacher (giảng viên, theo lớp) / student (học viên)
//
// Chống lách khóa/xóa tài khoản: mỗi request đều đối chiếu session với DB.
// Nếu tài khoản đã bị xóa hoặc bị khóa (is_active = 0) thì hủy session ngay
// lập tức — kể cả tab đang mở hay phiên đăng nhập khác — buộc đăng xuất.

require_once __DIR__ . '/../config/bootstrap.php';

final class Auth
{
    // Hủy toàn bộ phiên đăng nhập (dùng khi đăng xuất chủ động và khi bị khóa/xóa)
    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'] ?? '/',
                $params['domain'] ?? '',
                $params['secure'] ?? false,
                $params['httponly'] ?? true
            );
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    // Lấy user hiện tại, đồng thời xác thực lại với DB để khóa/xóa có hiệu lực ngay.
    public static function user(): ?array
    {
        $sess = $_SESSION['user'] ?? null;
        if (!$sess || !isset($sess['id'])) {
            return null;
        }

        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT id, username, full_name, role, class_code, xp_points, is_active FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$sess['id']]);
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            // Lỗi DB tạm thời: không đăng xuất oan, giữ nguyên session cũ.
            return $sess;
        }

        // Tài khoản đã bị xóa khỏi DB, hoặc bị admin khóa → đăng xuất ngay.
        if (!$row || (int)($row['is_active'] ?? 1) === 0) {
            self::logout();
            return null;
        }

        // Đồng bộ lại thông tin mới nhất từ DB (quyền, lớp, XP đổi thì có hiệu lực ngay).
        $_SESSION['user'] = [
            'id'         => (int)$row['id'],
            'username'   => $row['username'],
            'full_name'  => $row['full_name'],
            'role'       => $row['role'],
            'class_code' => $row['class_code'],
            'xp_points'  => (int)$row['xp_points'],
        ];
        return $_SESSION['user'];
    }

    public static function requireLogin(): array
    {
        $u = self::user();
        if (!$u) {
            header('Location: ' . url('/frontend/auth/login.php?reason=expired'));
            exit;
        }
        return $u;
    }

    // Dùng cho API (fetch): hết phiên thì trả JSON 401 thay vì redirect HTML
    // vì fetch sẽ nhận trang login HTML làm res.json() nổ exception.
    public static function requireApiLogin(): array
    {
        $u = self::user();
        if (!$u) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Hết phiên đăng nhập. Vui lòng đăng nhập lại.']);
            exit;
        }
        return $u;
    }

    public static function requireRole(array $roles): array
    {
        $u = self::requireLogin();
        if (!in_array($u['role'], $roles, true)) {
            http_response_code(403);
            die('403 Forbidden — Bạn không có quyền truy cập chức năng này.');
        }
        return $u;
    }

    // Dùng cho API phân quyền (fetch): trả JSON 403 thay vì die() text thuần.
    public static function requireApiRole(array $roles): array
    {
        $u = self::requireApiLogin();
        if (!in_array($u['role'], $roles, true)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => '403 Forbidden — Bạn không có quyền truy cập chức năng này.']);
            exit;
        }
        return $u;
    }

    // Kiểm tra quyền xem mã nguồn và chi tiết bài nộp theo tài khoản & lớp học
    public static function canViewSubmission(array $viewer, array $owner): bool
    {
        if ($viewer['role'] === 'admin') {
            return true;
        }
        if ((int)$viewer['id'] === (int)$owner['id']) {
            return true;
        }
        if ($viewer['role'] === 'teacher'
            && ($viewer['class_code'] === 'ALL' || $viewer['class_code'] === $owner['class_code'])) {
            return true;
        }
        return false;
    }

    public static function isTeacher(array $u): bool
    {
        return in_array($u['role'], ['teacher', 'admin'], true);
    }
}
