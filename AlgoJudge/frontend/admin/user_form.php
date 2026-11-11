<?php
// New accounts are provisioned only by administrators; class enrollment is explicit.
require_once __DIR__ . '/../../backend/lib/Auth.php';
require_once __DIR__ . '/../../backend/lib/AuditLog.php';
$user = Auth::requireRole(['admin']);
$err = '';
$text = static function (array $input, string $key): string {
    return is_string($input[$key] ?? null) ? trim($input[$key]) : '';
};
$fRole = $text($_GET, 'role');
if (!in_array($fRole, ['student', 'teacher', 'admin'], true)) $fRole = '';
$fSearch = $text($_GET, 'q');
$pageValue = $_GET['page'] ?? 1;
$returnPage = is_scalar($pageValue) && filter_var($pageValue, FILTER_VALIDATE_INT) !== false ? max(1, (int)$pageValue) : 1;
$returnQuery = array_filter(['role' => $fRole, 'q' => $fSearch, 'page' => $returnPage], static fn($value) => $value !== '');
$listUrl = url('/frontend/admin/users.php?' . http_build_query($returnQuery));
$formUrl = url('/frontend/admin/user_form.php?' . http_build_query($returnQuery));
$tUser = $text($_POST, 't_username');
$tEmail = $text($_POST, 't_email');
$tName = $text($_POST, 't_fullname');
$tRole = $_SERVER['REQUEST_METHOD'] === 'POST' ? $text($_POST, 't_role') : 'teacher';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $password = is_string($_POST['t_password'] ?? null) ? $_POST['t_password'] : '';
    $confirmation = is_string($_POST['t_password_confirm'] ?? null) ? $_POST['t_password_confirm'] : '';
    if (!preg_match('/\A[a-zA-Z0-9_]{3,50}\z/', $tUser)) {
        $err = 'Username chỉ gồm chữ, số, gạch dưới (3–50 ký tự).';
    } elseif (strlen($tEmail) > 100 || !filter_var($tEmail, FILTER_VALIDATE_EMAIL)) {
        $err = 'Email không hợp lệ hoặc dài hơn 100 ký tự.';
    } elseif ($tName === '' || mb_strlen($tName) > 100) {
        $err = 'Họ tên phải có từ 1 đến 100 ký tự.';
    } elseif (!in_array($tRole, ['student', 'teacher', 'admin'], true)) {
        $err = 'Hãy chọn vai trò hợp lệ.';
    } elseif (strlen($password) < 8 || strlen($password) > 72 || strpos($password, "\0") !== false) {
        $err = 'Mật khẩu phải có từ 8 đến 72 byte. Không dùng mật khẩu mặc định chung.';
    } elseif ($password !== $confirmation) {
        $err = 'Xác nhận mật khẩu không khớp.';
    } else {
        $ownsTransaction = false;
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($pdo->inTransaction()) throw new LogicException('Account creation requires its own transaction.');
            $pdo->beginTransaction();
            $ownsTransaction = true;
            $stmt = $pdo->prepare('SELECT role, is_active, auth_version FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([(int)$user['id']]);
            $actor = $stmt->fetch();
            if (!$actor || $actor['role'] !== 'admin' || (int)$actor['is_active'] !== 1
                || (isset($user['auth_version']) && (int)$user['auth_version'] !== (int)$actor['auth_version'])) {
                throw new DomainException('Quyền tài khoản đã thay đổi. Hãy đăng nhập lại.');
            }
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
            $stmt->execute([$tUser, $tEmail]);
            if ($stmt->fetchColumn()) throw new DomainException('Username hoặc email đã tồn tại.');
            // Never inherit the legacy database's nonempty class_code default.
            $pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, role, class_code) VALUES (?, ?, ?, ?, ?, '')")
                ->execute([$tUser, $tEmail, $hash, $tName, $tRole]);
            $newUserId = (int)$pdo->lastInsertId();
            AuditLog::record($pdo, $user, 'user.create', 'user', $newUserId, ['role' => $tRole, 'source' => 'ui']);
            $pdo->commit();
            $ownsTransaction = false;
            $_SESSION['admin_users_flash'] = ['ok' => true, 'message' => "Đã tạo tài khoản $tUser ($tRole). Tài khoản chưa có lớp; hãy gán tư cách thành viên hoặc quyền chủ quản tại Quản lý lớp."];
            header('Location: ' . $listUrl, true, 303);
            exit;
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            $err = $e instanceof DomainException ? $e->getMessage() : ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062
                ? 'Username hoặc email đã tồn tại.' : 'Không thể tạo tài khoản. Hãy kiểm tra thông tin và thử lại.');
        }
    }
    unset($password, $confirmation);
}
$pageTitle = 'Tạo tài khoản';
$activeNav = 'admin_new';
require FE_DIR . '/partials/layout_head.php';
?>
<div style="max-width:640px; margin:28px auto; padding:0 24px;">
    <h2 style="margin-bottom:6px;">Tạo tài khoản mới</h2>
    <p style="color:var(--cl-muted); margin-bottom:20px;">Đặt mật khẩu riêng cho từng tài khoản và chuyển cho người dùng qua kênh an toàn. Tài khoản mới không tự động thuộc lớp cũ.</p>
    <?php if ($err): ?><div role="alert" style="background:#fee2e2; color:#b91c1c; padding:12px 18px; border-radius:8px; margin-bottom:16px; font-weight:600;"><?= e($err) ?></div><?php endif; ?>
    <div style="background:#fff; padding:24px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
        <form method="POST" action="<?= e($formUrl) ?>">
            <?= csrf_field() ?>
            <div class="form-group"><label for="new-name">Họ tên</label><input id="new-name" type="text" name="t_fullname" class="form-control" required maxlength="100" value="<?= e($tName) ?>"></div>
            <div class="form-group"><label for="new-username">Username (chữ/số/_)</label><input id="new-username" type="text" name="t_username" class="form-control" required minlength="3" maxlength="50" pattern="[a-zA-Z0-9_]{3,50}" autocomplete="off" value="<?= e($tUser) ?>"></div>
            <div class="form-group"><label for="new-email">Email</label><input id="new-email" type="email" name="t_email" class="form-control" required maxlength="100" value="<?= e($tEmail) ?>"></div>
            <div class="form-group"><label for="new-role">Vai trò</label><select id="new-role" name="t_role" class="form-control" required>
                <?php foreach (['teacher' => 'teacher (giảng viên)', 'student' => 'student (học viên)', 'admin' => 'admin (quản trị)'] as $v => $label): ?><option value="<?= $v ?>" <?= $tRole === $v ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></div>
            <div class="form-group"><label for="new-password">Mật khẩu (8–72 byte)</label><input id="new-password" type="password" name="t_password" class="form-control" required minlength="8" maxlength="72" autocomplete="new-password"></div>
            <div class="form-group"><label for="confirm-password">Nhập lại mật khẩu</label><input id="confirm-password" type="password" name="t_password_confirm" class="form-control" required minlength="8" maxlength="72" autocomplete="new-password"></div>
            <p style="color:var(--cl-muted);">Sau khi tạo, gán học viên vào lớp hoặc gán giáo viên làm chủ quản tại <a href="<?= e(url('/frontend/instructor/classes.php')) ?>">Quản lý lớp</a>. Giáo viên không được tạo tài khoản hay chỉnh sửa hồ sơ học viên.</p>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary" style="flex:1; justify-content:center;">Tạo tài khoản</button>
                <a class="btn btn-secondary btn-back" href="<?= e($listUrl) ?>">Danh sách</a>
            </div>
        </form>
    </div>
</div>
<?php require FE_DIR . '/partials/layout_foot.php'; ?>
