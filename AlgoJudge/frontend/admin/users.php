<?php
// frontend/admin/users.php — Quản trị người dùng (chỉ admin)
// Cho phép: đổi vai trò (student/teacher/admin), đổi lớp, reset mật khẩu, khóa/mở tài khoản.
require_once __DIR__ . '/../../backend/lib/Auth.php';
$user = Auth::requireRole(['admin']);
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $targetId = (int)($_POST['target_id'] ?? 0);

    if ($action === 'set_role' && $targetId > 0 && $targetId !== (int)$user['id']) {
        $newRole = $_POST['role'] ?? 'student';
        if (in_array($newRole, ['student', 'teacher', 'admin'], true)) {
            $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $targetId]);
            $msg = "Đã đổi vai trò user #$targetId thành $newRole.";
        }
    } elseif ($action === 'set_class' && $targetId > 0) {
        $newClass = trim($_POST['class_code'] ?? '');
        if ($newClass !== '') {
            $pdo->prepare("UPDATE users SET class_code = ? WHERE id = ?")->execute([$newClass, $targetId]);
            $msg = "Đã đổi lớp user #$targetId thành $newClass.";
        }
    } elseif ($action === 'reset_pwd' && $targetId > 0) {
        $hash = password_hash('123456', PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $targetId]);
        $msg = "Đã reset mật khẩu user #$targetId về 123456.";
    } elseif ($action === 'toggle_lock' && $targetId > 0) {
        if ($targetId === (int)$user['id']) {
            $msg = 'Không thể khóa chính tài khoản của bạn!';
        } else {
            $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id = ?")->execute([$targetId]);
            $st = $pdo->query("SELECT is_active FROM users WHERE id = $targetId")->fetchColumn();
            $msg = ((int)$st === 0 ? 'Đã khóa' : 'Đã mở khóa') . " tài khoản #$targetId.";
        }
    } elseif ($action === 'delete_user' && $targetId > 0) {
        if ($targetId === (int)$user['id']) {
            $msg = 'Không thể xóa chính tài khoản của bạn!';
        } else {
            $cntSub = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE user_id = ?");
            $cntSub->execute([$targetId]);
            $cntProb = $pdo->prepare("SELECT COUNT(*) FROM problems WHERE created_by = ?");
            $cntProb->execute([$targetId]);
            $nSub = (int)$cntSub->fetchColumn();
            $nProb = (int)$cntProb->fetchColumn();
            if ($nSub > 0 || $nProb > 0) {
                $msg = "Không thể xóa tài khoản #$targetId vì còn $nSub bài nộp và $nProb bài tập liên quan. Hãy Khóa tài khoản thay vì xóa để giữ lịch sử chấm.";
            } else {
                $del = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $del->execute([$targetId]);
                $msg = $del->rowCount() > 0 ? "Đã xóa tài khoản #$targetId." : "Không xóa được tài khoản #$targetId.";
            }
        }
    } elseif ($action === 'create_user') {
        // Đã chuyển sang trang riêng frontend/admin/user_form.php
        $msg = 'Chức năng tạo tài khoản đã chuyển sang trang "Tạo tài khoản".';
    }
}

$fRole = $_GET['role'] ?? '';
$fSearch = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

$where = [];
$params = [];
if (in_array($fRole, ['student', 'teacher', 'admin'], true)) {
    $where[] = 'u.role = ?';
    $params[] = $fRole;
}
if ($fSearch !== '') {
    $where[] = '(u.username LIKE ? OR u.full_name LIKE ?)';
    $params[] = "%$fSearch%";
    $params[] = "%$fSearch%";
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM users u $whereSql");
$stmtCnt->execute($params);
$totalRows = (int)$stmtCnt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT u.*, (SELECT COUNT(*) FROM submissions s WHERE s.user_id = u.id) AS sub_count
    FROM users u $whereSql ORDER BY u.id ASC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$users = $stmt->fetchAll();
$baseQuery = array_filter(['role' => $fRole ?: null, 'q' => $fSearch ?: null]);

$pageTitle = 'Quản trị User';
$activeNav = 'admin';
require FE_DIR . '/partials/layout_head.php';
?>
<div style="max-width:1280px; margin:28px auto; padding:0 24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
        <h2>Danh sách tài khoản &amp; phân quyền</h2>
        <a class="btn btn-success" href="<?= url('/frontend/admin/user_form.php') ?>">+ Tạo tài khoản</a>
    </div>
    <p style="color:var(--cl-muted); margin-bottom:20px;">3 vai trò: <code>student</code> (học viên) · <code>teacher</code> (giảng viên, quản lý theo lớp) · <code>admin</code> (toàn quyền).</p>

    <?php if ($msg): ?>
        <div style="background:#dcfce7; color:#15803d; padding:12px 18px; border-radius:8px; margin-bottom:20px; font-weight:600;">✔ <?= e($msg) ?></div>
    <?php endif; ?>

    <div style="background:#fff; padding:24px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
            <h3 style="margin-bottom:12px;">Danh sách tài khoản (<?= $totalRows ?>)</h3>
            <form method="GET" style="display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap;">
                <select name="role" class="form-control" style="max-width:160px; padding:6px 10px;">
                    <option value="">-- Mọi vai trò --</option>
                    <?php foreach (['student', 'teacher', 'admin'] as $r): ?>
                        <option value="<?= $r ?>" <?= $fRole === $r ? 'selected' : '' ?>><?= $r ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="q" class="form-control" style="max-width:200px; padding:6px 10px;" placeholder="Tìm username / họ tên..." value="<?= e($fSearch) ?>">
                <button type="submit" class="btn btn-primary" style="padding:6px 14px;">Lọc</button>
                <a href="?" class="btn" style="background:#e2e8f0; padding:6px 14px;">Đặt lại</a>
            </form>
            <table style="width:100%; border-collapse:collapse; font-size:0.88rem;">
                <thead>
                    <tr style="background:#f8fafc; border-bottom:1px solid var(--cl-border); text-align:left;">
                        <th style="padding:10px;">ID</th>
                        <th style="padding:10px;">Username / Họ tên</th>
                        <th style="padding:10px;">Vai trò</th>
                        <th style="padding:10px;">Lớp</th>
                        <th style="padding:10px;">Bài nộp</th>
                        <th style="padding:10px;">Trạng thái</th>
                        <th style="padding:10px; text-align:right;">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr style="border-bottom:1px solid var(--cl-border);">
                        <td style="padding:10px; font-weight:700;">#<?= (int)$u['id'] ?></td>
                        <td style="padding:10px;"><strong><?= e($u['username']) ?></strong><br><small><?= e($u['full_name']) ?></small></td>
                        <td style="padding:10px;">
                            <form method="POST" style="display:flex; gap:6px;">
                                <input type="hidden" name="action" value="set_role">
                                <input type="hidden" name="target_id" value="<?= (int)$u['id'] ?>">
                                <select name="role" class="form-control" style="padding:4px 8px; font-size:0.82rem;">
                                    <?php foreach (['student', 'teacher', 'admin'] as $r): ?>
                                        <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= $r ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-primary" style="padding:4px 10px; font-size:0.78rem;">Lưu</button>
                            </form>
                        </td>
                        <td style="padding:10px;">
                            <form method="POST" style="display:flex; gap:6px;">
                                <input type="hidden" name="action" value="set_class">
                                <input type="hidden" name="target_id" value="<?= (int)$u['id'] ?>">
                                <input type="text" name="class_code" value="<?= e($u['class_code']) ?>" class="form-control" style="padding:4px 8px; font-size:0.82rem; width:110px;">
                                <button class="btn" style="background:#e2e8f0; padding:4px 10px; font-size:0.78rem;">Lưu</button>
                            </form>
                        </td>
                        <td style="padding:10px;"><?= (int)$u['sub_count'] ?></td>
                        <td style="padding:10px;">
                            <?php $locked = ((int)($u['is_active'] ?? 1) === 0); ?>
                            <?= $locked ? '<span class="badge verdict-CE">🔒 Bị khóa</span>' : '<span class="badge verdict-AC">✔ Hoạt động</span>' ?>
                        </td>
                        <td style="padding:10px; text-align:right;">
                            <form method="POST" style="display:inline;" onsubmit="return confirm('<?= $locked ? 'Mở khóa' : 'Khóa' ?> tài khoản <?= e($u['username']) ?>?');">
                                <input type="hidden" name="action" value="toggle_lock">
                                <input type="hidden" name="target_id" value="<?= (int)$u['id'] ?>">
                                <button class="btn" style="background:#fef3c7; padding:4px 10px; font-size:0.78rem;"><?= $locked ? 'Mở khóa' : 'Khóa' ?></button>
                            </form>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Reset mật khẩu <?= e($u['username']) ?> về 123456?');">
                                <input type="hidden" name="action" value="reset_pwd">
                                <input type="hidden" name="target_id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-outline" style="color:#475569; border-color:#cbd5e1; padding:4px 10px; font-size:0.78rem;">Reset MK</button>
                            </form>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('XÓA tài khoản <?= e($u['username']) ?>? Chỉ xóa được khi chưa có bài nộp/bài tập nào.');">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="target_id" value="<?= (int)$u['id'] ?>">
                                <button class="btn" style="background:#fee2e2; color:#b91c1c; padding:4px 10px; font-size:0.78rem;">Xóa</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php require FE_DIR . '/partials/pagination.php'; ?>
    </div>
</div>
<?php require FE_DIR . '/partials/layout_foot.php'; ?>
