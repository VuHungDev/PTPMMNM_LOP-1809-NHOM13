<?php
// frontend/admin/user_form.php — Tạo tài khoản mới (chỉ admin)
// Tách khỏi users.php để mỗi trang một chức năng: trang này chỉ tạo, users.php chỉ quản lý.
require_once __DIR__ . '/../../backend/lib/Auth.php';
$user = Auth::requireRole(['admin']);
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tUser = trim($_POST['t_username'] ?? '');
    $tEmail = trim($_POST['t_email'] ?? '');
    $tName = trim($_POST['t_fullname'] ?? '');
    $tClass = trim($_POST['t_class'] ?? 'CNTT-18-02');
    $tRole = in_array($_POST['t_role'] ?? '', ['student', 'teacher', 'admin'], true) ? $_POST['t_role'] : 'teacher';
    if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $tUser)) {
        $err = 'Username chỉ gồm chữ, số, gạch dưới (3–50 ký tự).';
    } elseif (!filter_var($tEmail, FILTER_VALIDATE_EMAIL)) {
        $err = 'Email không hợp lệ.';
    } elseif ($tName === '') {
        $err = 'Vui lòng nhập họ tên.';
    } else {
        $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $chk->execute([$tUser, $tEmail]);
        if (!$chk->fetch()) {
            $hash = password_hash('123456', PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, role, class_code) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$tUser, $tEmail, $hash, $tName, $tRole, $tClass]);
            header('Location: ' . url('/frontend/admin/users.php?q=' . urlencode($tUser)));
            exit;
        } else {
            $err = 'Username hoặc Email đã tồn tại!';
        }
    }
}

$pageTitle = 'Tạo tài khoản';
$activeNav = 'admin_new';
require FE_DIR . '/partials/layout_head.php';
?>
<div style="max-width:640px; margin:28px auto; padding:0 24px;">
    <h2 style="margin-bottom:6px;">Tạo tài khoản mới</h2>
    <p style="color:var(--cl-muted); margin-bottom:20px;">Mật khẩu mặc định: <code>123456</code> — người dùng đổi sau khi đăng nhập.</p>

    <?php if ($err): ?>
        <div style="background:#fee2e2; color:#b91c1c; padding:12px 18px; border-radius:8px; margin-bottom:16px; font-weight:600;">✘ <?= e($err) ?></div>
    <?php endif; ?>

    <div style="background:#fff; padding:24px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
        <form method="POST">
            <div class="form-group"><label>Họ tên</label><input type="text" name="t_fullname" class="form-control" required maxlength="100" value="<?= e($_POST['t_fullname'] ?? '') ?>"></div>
            <div class="form-group"><label>Username (chữ/số/_)</label><input type="text" name="t_username" class="form-control" required maxlength="50" pattern="[a-zA-Z0-9_]{3,50}" value="<?= e($_POST['t_username'] ?? '') ?>"></div>
            <div class="form-group"><label>Email</label><input type="email" name="t_email" class="form-control" required maxlength="100" value="<?= e($_POST['t_email'] ?? '') ?>"></div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <div class="form-group"><label>Vai trò</label>
                    <select name="t_role" class="form-control">
                        <?php foreach (['teacher' => 'teacher (giảng viên)', 'student' => 'student (học viên)', 'admin' => 'admin (quản trị)'] as $v => $label): ?>
                            <option value="<?= $v ?>" <?= ($_POST['t_role'] ?? 'teacher') === $v ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Lớp quản lý (ALL = mọi lớp)</label><input type="text" name="t_class" class="form-control" value="<?= e($_POST['t_class'] ?? 'CNTT-18-02') ?>" required maxlength="30"></div>
            </div>
            <div style="display:flex; gap:10px;">
                <button type="submit" class="btn btn-primary" style="flex:1; justify-content:center;">+ Tạo tài khoản</button>
                <a class="btn" style="background:#e2e8f0;" href="<?= url('/frontend/admin/users.php') ?>">Danh sách</a>
            </div>
        </form>
    </div>
</div>
<?php require FE_DIR . '/partials/layout_foot.php'; ?>
