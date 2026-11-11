<?php
// Admin-only account management. Membership/ownership changes are explicit and retain history.
require_once __DIR__ . '/../../backend/lib/Auth.php';
require_once __DIR__ . '/../../backend/lib/AuditLog.php';
$user = Auth::requireRole(['admin']);
$text = static function (array $input, string $key): string {
    return is_string($input[$key] ?? null) ? trim($input[$key]) : '';
};
$integer = static function (array $input, string $key, int $default = 0): int {
    $value = $input[$key] ?? null;
    return is_scalar($value) && filter_var($value, FILTER_VALIDATE_INT) !== false ? (int)$value : $default;
};
$fRole = $text($_GET, 'role');
if (!in_array($fRole, ['student', 'teacher', 'admin'], true)) $fRole = '';
$fSearch = $text($_GET, 'q');
$page = max(1, $integer($_GET, 'page', 1));
$baseQuery = array_filter(['role' => $fRole, 'q' => $fSearch], static fn($value) => $value !== '');
$returnQuery = array_merge($baseQuery, ['page' => $page]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $text($_POST, 'action');
    $targetId = $integer($_POST, 'target_id');
    $msgOk = true;
    $ownsTransaction = false;
    try {
        if (!in_array($action, ['set_role', 'reset_pwd', 'toggle_lock', 'delete_user'], true) || $targetId < 1) {
            throw new DomainException('Thao tác hoặc tài khoản không hợp lệ.');
        }
        if ($targetId === (int)$user['id']) {
            throw new DomainException('Không thể đổi vai trò, khóa, xóa hoặc reset chính tài khoản quản trị đang đăng nhập. Nếu cần đặt lại mật khẩu, hãy nhờ quản trị viên khác hoặc dùng chức năng Quên mật khẩu.');
        }
        $newRole = $text($_POST, 'role');
        if ($action === 'set_role' && !in_array($newRole, ['student', 'teacher', 'admin'], true)) {
            throw new DomainException('Vai trò không hợp lệ.');
        }
        $hash = null;
        if ($action === 'reset_pwd') {
            $password = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
            if (strlen($password) < 8 || strlen($password) > 72 || strpos($password, "\0") !== false) {
                throw new DomainException('Mật khẩu mới phải có từ 8 đến 72 byte. Không dùng mật khẩu mặc định chung.');
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            unset($password);
        }

        if ($pdo->inTransaction()) throw new LogicException('Account mutation requires its own transaction.');
        $pdo->beginTransaction();
        $ownsTransaction = true;
        // Shared lock order with ClassManager prevents a simultaneous approval/assignment
        // from leaving a demoted account as a live member or a non-teacher class owner.
        $ids = [(int)$user['id'], $targetId];
        sort($ids, SORT_NUMERIC);
        $stmt = $pdo->prepare('SELECT id, username, role, is_active, auth_version FROM users WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
        $stmt->execute($ids);
        $lockedUsers = [];
        foreach ($stmt->fetchAll() as $row) $lockedUsers[(int)$row['id']] = $row;
        $actor = $lockedUsers[(int)$user['id']] ?? null;
        $target = $lockedUsers[$targetId] ?? null;
        if (!$actor || $actor['role'] !== 'admin' || (int)$actor['is_active'] !== 1
            || (isset($user['auth_version']) && (int)$user['auth_version'] !== (int)$actor['auth_version'])) {
            throw new DomainException('Quyền tài khoản đã thay đổi. Hãy đăng nhập lại.');
        }
        if (!$target) throw new DomainException('Tài khoản không còn tồn tại.');
        $credentialsChanged = false;
        $auditAction = null;
        $auditMetadata = ['source' => 'ui'];

        if ($action === 'set_role') {
            if ($newRole === $target['role']) {
                $msg = 'Vai trò đã đúng như lựa chọn; không có thay đổi.';
            } else {
                $stmt = $pdo->prepare('SELECT id FROM classes WHERE owner_teacher_id = ? ORDER BY id FOR UPDATE');
                $stmt->execute([$targetId]);
                $ownedClasses = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if ($newRole !== 'teacher' && $ownedClasses) {
                    throw new DomainException('Không thể đổi vai trò: tài khoản đang chủ quản ' . count($ownedClasses) . ' lớp. Hãy dùng liên kết “Gán lại chủ quản” ở tài khoản này để chuyển từng lớp cho giáo viên khác trước.');
                }
                $pdo->prepare('UPDATE users SET role = ?, auth_version = auth_version + 1 WHERE id = ?')->execute([$newRole, $targetId]);
                $pdo->prepare("UPDATE class_members SET status = 'removed', updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND status IN ('active', 'pending')")
                    ->execute([$targetId]);
                $credentialsChanged = true;
                $auditAction = 'user.role_change';
                $auditMetadata += ['role' => $newRole, 'previous_role' => $target['role']];
                $msg = "Đã đổi vai trò tài khoản #$targetId thành $newRole, thu hồi mọi tư cách thành viên/yêu cầu đang chờ và phiên đăng nhập cũ. Lịch sử vẫn được giữ lại.";
            }
        } elseif ($action === 'reset_pwd') {
            $pdo->prepare('UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ?')->execute([$hash, $targetId]);
            $credentialsChanged = true;
            $auditAction = 'user.password_reset';
            $msg = "Đã đặt mật khẩu mới cho tài khoản #$targetId. Các phiên đăng nhập và mã khôi phục cũ đã bị thu hồi.";
        } elseif ($action === 'toggle_lock') {
            $newActive = (int)$target['is_active'] === 1 ? 0 : 1;
            $pdo->prepare('UPDATE users SET is_active = ?, auth_version = auth_version + 1 WHERE id = ?')->execute([$newActive, $targetId]);
            $credentialsChanged = true;
            $auditAction = $newActive ? 'user.unlock' : 'user.lock';
            $msg = ($newActive ? 'Đã mở khóa' : 'Đã khóa') . " tài khoản #$targetId. Các phiên đăng nhập và mã khôi phục cũ đã bị thu hồi.";
        } elseif ($action === 'delete_user') {
            $stmt = $pdo->prepare('SELECT (SELECT COUNT(*) FROM submissions WHERE user_id = ?) AS submissions_count,
                (SELECT COUNT(*) FROM problems WHERE created_by = ?) AS problems_count,
                (SELECT COUNT(*) FROM problem_versions WHERE created_by = ?) AS versions_count,
                (SELECT COUNT(*) FROM class_members WHERE user_id = ?) AS memberships_count,
                (SELECT COUNT(*) FROM classes WHERE owner_teacher_id = ? OR created_by = ?) AS classes_count');
            $stmt->execute(array_fill(0, 6, $targetId));
            $counts = $stmt->fetch();
            if (array_sum(array_map('intval', $counts)) > 0) {
                throw new DomainException('Không thể xóa tài khoản có bài nộp, bài tập, lớp hoặc lịch sử thành viên. Hãy khóa tài khoản để giữ lịch sử; gán lại các lớp đang chủ quản nếu cần.');
            }
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$targetId]);
            $auditAction = 'user.delete';
            $auditMetadata['role'] = $target['role'];
            $msg = "Đã xóa tài khoản chưa có dữ liệu liên quan #$targetId.";
        }
        if ($credentialsChanged) {
            $pdo->prepare('UPDATE password_reset_otps SET used_at = CURRENT_TIMESTAMP WHERE user_id = ? AND used_at IS NULL')->execute([$targetId]);
        }
        if ($auditAction !== null) {
            AuditLog::record($pdo, $user, $auditAction, 'user', $targetId, $auditMetadata);
        }
        $pdo->commit();
        $ownsTransaction = false;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        $msg = $e instanceof DomainException ? $e->getMessage() : ($action === 'delete_user'
            ? 'Không thể xóa tài khoản. Tài khoản có thể còn dữ liệu liên quan; hãy khóa thay vì xóa để giữ lịch sử.'
            : 'Không thể hoàn tất thao tác. Hãy tải lại trang và thử lại.');
        $msgOk = false;
    }
    $_SESSION['admin_users_flash'] = ['message' => $msg, 'ok' => $msgOk];
    header('Location: ' . url('/frontend/admin/users.php?' . http_build_query($returnQuery)), true, 303);
    exit;
}

$flash = $_SESSION['admin_users_flash'] ?? null;
unset($_SESSION['admin_users_flash']);
$perPage = 10;
$where = [];
$params = [];
if ($fRole !== '') {
    $where[] = 'u.role = ?';
    $params[] = $fRole;
}
if ($fSearch !== '') {
    // Keep q identical to the users export: username/full_name only, not email.
    $where[] = '(u.username LIKE ? OR u.full_name LIKE ?)';
    $params[] = "%$fSearch%";
    $params[] = "%$fSearch%";
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users u $whereSql");
$stmt->execute($params);
$totalRows = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$stmt = $pdo->prepare("SELECT u.id, u.username, u.full_name, u.role, u.is_active,
    (SELECT COUNT(*) FROM submissions s WHERE s.user_id = u.id) AS sub_count,
    (SELECT COUNT(*) FROM classes c WHERE c.owner_teacher_id = u.id) AS owned_class_count,
    (SELECT GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') FROM class_members cm JOIN classes c ON c.id = cm.class_id WHERE cm.user_id = u.id AND cm.status = 'active') AS class_names
    FROM users u $whereSql ORDER BY u.id ASC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$users = $stmt->fetchAll();
$returnQuery = array_merge($baseQuery, ['page' => $page]);
$formUrl = url('/frontend/admin/users.php?' . http_build_query($returnQuery));
$pageTitle = 'Quản trị User';
$activeNav = 'admin';
require FE_DIR . '/partials/layout_head.php';
?>
<div style="max-width:1280px; margin:28px auto; padding:0 24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:6px;">
        <h2>Danh sách tài khoản &amp; phân quyền</h2>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a class="btn btn-success" href="<?= e(url('/backend/api/export_excel.php?' . http_build_query(array_merge(['type' => 'users'], $baseQuery)))) ?>">Xuất Excel theo bộ lọc</a>
            <a class="btn btn-primary" href="<?= e(url('/frontend/admin/user_form.php?' . http_build_query($returnQuery))) ?>">+ Tạo tài khoản</a>
        </div>
    </div>
    <p style="color:var(--cl-muted); margin-bottom:12px;">3 vai trò: <code>student</code> (học viên) · <code>teacher</code> (giảng viên, quản lý lớp được giao) · <code>admin</code> (toàn quyền).</p>
    <p style="color:var(--cl-muted); margin-bottom:20px;">Đổi vai trò thu hồi mọi tư cách thành viên/yêu cầu đang chờ, mã khôi phục và phiên đăng nhập cũ. Giáo viên đang chủ quản lớp phải được gán lại lớp trước khi đổi sang vai trò khác.</p>
    <?php if ($flash): ?>
        <div role="status" style="background:<?= $flash['ok'] ? '#dcfce7; color:#15803d' : '#fee2e2; color:#b91c1c' ?>; padding:12px 18px; border-radius:8px; margin-bottom:20px; font-weight:600;"><?= e($flash['message']) ?></div>
    <?php endif; ?>
    <div style="background:#fff; padding:24px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
        <h3 style="margin-bottom:12px;">Danh sách tài khoản (<?= $totalRows ?>)</h3>
        <form method="GET" action="<?= e(url('/frontend/admin/users.php')) ?>" style="display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap;">
            <select name="role" class="form-control" aria-label="Lọc vai trò" style="max-width:160px; padding:6px 10px;">
                <option value="">-- Mọi vai trò --</option>
                <?php foreach (['student', 'teacher', 'admin'] as $r): ?><option value="<?= $r ?>" <?= $fRole === $r ? 'selected' : '' ?>><?= $r ?></option><?php endforeach; ?>
            </select>
            <input type="text" name="q" class="form-control" aria-label="Tìm username hoặc họ tên" style="max-width:240px; padding:6px 10px;" placeholder="Tìm username / họ tên..." value="<?= e($fSearch) ?>">
            <button type="submit" class="btn btn-primary" style="padding:6px 14px;">Lọc</button>
            <a href="<?= e(url('/frontend/admin/users.php')) ?>" class="btn" style="background:#e2e8f0; padding:6px 14px;">Đặt lại</a>
        </form>
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:0.88rem;">
                <thead><tr style="background:#f8fafc; border-bottom:1px solid var(--cl-border); text-align:left;">
                    <th style="padding:10px;">ID</th><th style="padding:10px;">Username / Họ tên</th><th style="padding:10px;">Vai trò</th><th style="padding:10px;">Lớp</th><th style="padding:10px;">Bài nộp</th><th style="padding:10px;">Trạng thái</th><th style="padding:10px; text-align:right;">Thao tác</th>
                </tr></thead>
                <tbody>
                <?php foreach ($users as $u): $isSelf = (int)$u['id'] === (int)$user['id']; $locked = (int)$u['is_active'] === 0; ?>
                    <tr style="border-bottom:1px solid var(--cl-border);">
                        <td style="padding:10px; font-weight:700;">#<?= (int)$u['id'] ?></td>
                        <td style="padding:10px;"><strong><?= e($u['username']) ?></strong><br><small><?= e($u['full_name']) ?></small></td>
                        <td style="padding:10px;">
                            <?php if ($isSelf): ?><code><?= e($u['role']) ?></code> <small>(Bạn)</small><?php else: ?>
                                <form method="POST" action="<?= e($formUrl) ?>" style="display:flex; gap:6px;" onsubmit="return confirm('Lưu vai trò? Nếu vai trò thay đổi, tư cách thành viên, yêu cầu chờ và các phiên đăng nhập cũ sẽ bị thu hồi.');">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="set_role"><input type="hidden" name="target_id" value="<?= (int)$u['id'] ?>">
                                    <select name="role" class="form-control" aria-label="Vai trò của <?= e($u['username']) ?>" style="padding:4px 8px; font-size:0.82rem;">
                                        <?php foreach (['student', 'teacher', 'admin'] as $r): ?><option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= $r ?></option><?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-primary" style="padding:4px 10px; font-size:0.78rem;">Lưu</button>
                                </form>
                            <?php endif; ?>
                            <?php if ((int)$u['owned_class_count'] > 0): ?><small style="display:block; margin-top:6px;">Đang chủ quản <?= (int)$u['owned_class_count'] ?> lớp. <a href="<?= e(url('/frontend/instructor/classes.php?owner_id=' . (int)$u['id'])) ?>">Gán lại chủ quản</a> trước khi đổi vai trò.</small><?php endif; ?>
                        </td>
                        <td style="padding:10px;">
                            <?php if ($u['role'] === 'student'): ?>
                                <a href="<?= e(url('/frontend/instructor/classes.php?student_id=' . (int)$u['id'])) ?>"><?= e($u['class_names'] ?: 'Gán lớp') ?></a>
                            <?php elseif ($u['role'] === 'teacher'): ?>
                                <a href="<?= e(url('/frontend/instructor/classes.php?owner_id=' . (int)$u['id'])) ?>"><?= (int)$u['owned_class_count'] ?> lớp chủ quản</a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td style="padding:10px;"><?= (int)$u['sub_count'] ?></td>
                        <td style="padding:10px;"><span class="badge <?= $locked ? 'verdict-CE' : 'verdict-AC' ?>"><?= $locked ? 'Bị khóa' : 'Hoạt động' ?></span></td>
                        <td style="padding:10px; text-align:right;">
                            <?php if ($isSelf): ?><a href="<?= e(url('/frontend/profile.php')) ?>">Hồ sơ quản trị</a><?php else: ?>
                                <form method="POST" action="<?= e($formUrl) ?>" style="display:inline;" onsubmit="return confirm('<?= $locked ? 'Mở khóa' : 'Khóa' ?> tài khoản này? Các phiên đăng nhập và mã khôi phục cũ sẽ bị thu hồi.');">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="toggle_lock"><input type="hidden" name="target_id" value="<?= (int)$u['id'] ?>">
                                    <button type="submit" class="btn" style="background:#fef3c7; padding:4px 10px; font-size:0.78rem;"><?= $locked ? 'Mở khóa' : 'Khóa' ?></button>
                                </form>
                                <form method="POST" action="<?= e($formUrl) ?>" style="display:inline;" onsubmit="return confirm('Xóa tài khoản chưa có dữ liệu liên quan? Nếu cần giữ lịch sử, hãy khóa thay vì xóa.');">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="target_id" value="<?= (int)$u['id'] ?>">
                                    <button type="submit" class="btn" style="background:#fee2e2; color:#b91c1c; padding:4px 10px; font-size:0.78rem;">Xóa</button>
                                </form>
                                <details style="margin-top:8px; text-align:left; min-width:180px;">
                                    <summary style="cursor:pointer;">Đặt mật khẩu mới</summary>
                                    <form method="POST" action="<?= e($formUrl) ?>" style="margin-top:8px;" onsubmit="return confirm('Đặt mật khẩu mới và thu hồi mọi phiên đăng nhập/mã khôi phục cũ của tài khoản này?');">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="reset_pwd"><input type="hidden" name="target_id" value="<?= (int)$u['id'] ?>">
                                        <input type="password" name="new_password" class="form-control" required minlength="8" maxlength="72" autocomplete="new-password" aria-label="Mật khẩu mới của <?= e($u['username']) ?>" placeholder="Mật khẩu mới (8–72 byte)" style="margin-bottom:6px;">
                                        <button type="submit" class="btn btn-primary" style="padding:4px 10px; font-size:0.78rem;">Đặt mật khẩu</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?><tr><td colspan="7" style="padding:20px; text-align:center;">Không tìm thấy tài khoản phù hợp.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php require FE_DIR . '/partials/pagination.php'; ?>
    </div>
</div>
<?php require FE_DIR . '/partials/layout_foot.php'; ?>
