<?php
// frontend/auth/register.php — Chỉ tạo tài khoản học viên (student)
require_once __DIR__ . '/../../backend/lib/Auth.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username   = trim($_POST['username'] ?? '');
    $email      = strtolower(trim($_POST['email'] ?? ''));
    $fullName   = trim($_POST['full_name'] ?? '');
    $password   = $_POST['password'] ?? '';
    $confirmPwd = $_POST['confirm_password'] ?? '';

    if ($username === '' || $email === '' || $fullName === '') {
        $error = 'Vui lòng nhập đầy đủ thông tin!';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,50}$/D', $username) || mb_strlen($fullName) > 100) {
        $error = 'Username cần 3–50 chữ cái, số hoặc dấu gạch dưới; họ tên tối đa 100 ký tự.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        $error = 'Email không hợp lệ.';
    } elseif ($password !== $confirmPwd) {
        $error = 'Mật khẩu xác nhận không khớp!';
    } elseif (strlen($password) < 8 || strlen($password) > 72) {
        $error = 'Mật khẩu cần từ 8 đến 72 byte.';
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $check->execute([$username, $email]);
        if ($check->fetch()) {
            $error = 'Username hoặc Email này đã được sử dụng!';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins = $pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, role, class_code) VALUES (?, ?, ?, ?, 'student', '')");
                $ins->execute([$username, $email, $hash, $fullName]);
                Auth::login([
                    'id' => (int)$pdo->lastInsertId(), 'username' => $username,
                    'full_name' => $fullName, 'role' => 'student', 'auth_version' => 1,
                ]);
                header('Location: ' . url('/frontend/index.php'));
                exit;
            } catch (PDOException $e) {
                $error = $e->getCode() === '23000' ? 'Username hoặc email đã được sử dụng.' : 'Không thể đăng ký lúc này. Hãy thử lại.';
            }
        }
    }
}
$pageTitle = 'Đăng ký';
require FE_DIR . '/partials/layout_head.php';
?>
<div class="auth-page">
    <div class="auth-banner">
        <div class="cl-brand" style="font-size:1.6rem;"><?php $logoSize = 44; require FE_DIR . '/partials/logo.php'; ?></div>
        <div>
            <h1 style="font-size:2rem; margin-bottom:12px;">Tạo tài khoản học viên</h1>
            <p style="opacity:0.85;">Tài khoản giảng viên / quản trị do admin cấp trong trang Quản trị.</p>
        </div>
        <div></div>
    </div>
    <div class="auth-form-wrap">
        <div class="auth-card">
            <h2 style="margin-bottom:20px;">Đăng ký</h2>
            <?php if ($error): ?><div style="background:#fee2e2; color:#b91c1c; padding:10px 14px; border-radius:8px; margin-bottom:14px;"><?= e($error) ?></div><?php endif; ?>
            <form method="POST">
                <?= csrf_field() ?>
                <div class="form-group"><label>Họ tên</label><input type="text" name="full_name" class="form-control" required></div>
                <div class="form-group"><label>Tên đăng nhập</label><input type="text" name="username" class="form-control" required></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" required></div>
                <p style="font-size:0.88rem; color:var(--cl-muted);">Sau khi đăng ký, bạn có thể nhập mã lớp được giáo viên cung cấp trong mục Lớp học; quyền tham gia cần được giáo viên duyệt.</p>
                <div class="form-group"><label>Mật khẩu</label><input type="password" name="password" class="form-control" required></div>
                <div class="form-group"><label>Xác nhận mật khẩu</label><input type="password" name="confirm_password" class="form-control" required></div>
                <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">Đăng ký</button>
            </form>
            <p style="margin-top:14px; font-size:0.9rem;">Đã có tài khoản? <a href="<?= url('/frontend/auth/login.php') ?>">Đăng nhập</a></p>
        </div>
    </div>
</div>
<?php require FE_DIR . '/partials/layout_foot.php'; ?>
