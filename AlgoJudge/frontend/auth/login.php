<?php
// frontend/auth/login.php
require_once __DIR__ . '/../../backend/lib/Auth.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $u = $stmt->fetch();
    if ($u && password_verify($password, $u['password_hash'])) {
        if ((int)($u['is_active'] ?? 1) === 0) {
            $error = 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ quản trị viên.';
        } else {
            $_SESSION['user'] = [
                'id' => (int)$u['id'],
                'username' => $u['username'],
                'full_name' => $u['full_name'],
                'role' => $u['role'],
                'class_code' => $u['class_code'],
                'xp_points' => (int)$u['xp_points'],
            ];
            header('Location: ' . url('/frontend/index.php'));
            exit;
        }
    } else {
        $error = 'Sai tên đăng nhập hoặc mật khẩu!';
    }
}
$pageTitle = 'Đăng nhập';
require FE_DIR . '/partials/layout_head.php';
?>
<div class="auth-page">
    <div class="auth-banner">
        <div class="cl-brand" style="font-size:1.6rem;"><?php $logoSize = 44; require FE_DIR . '/partials/logo.php'; ?></div>
        <div>
            <h1 style="font-size:2rem; margin-bottom:12px;">Luyện tập &amp; Chấm bài tự động</h1>
            <p style="opacity:0.85;">6 ngôn ngữ C / C++ / Java / Python / PHP / JS — chạy trên cả Windows &amp; Linux.</p>
        </div>
        <div style="opacity:0.7; font-size:0.85rem;">Tài khoản demo (mật khẩu 123456): sv_son · sv_phuong · gv_cntt · admin</div>
    </div>
    <div class="auth-form-wrap">
        <div class="auth-card">
            <h2 style="margin-bottom:20px;">Đăng nhập</h2>
            <?php if ($error): ?><div style="background:#fee2e2; color:#b91c1c; padding:10px 14px; border-radius:8px; margin-bottom:14px;"><?= e($error) ?></div><?php endif; ?>
            <form method="POST">
                <div class="form-group"><label>Tên đăng nhập</label><input type="text" name="username" class="form-control" required></div>
                <div class="form-group"><label>Mật khẩu</label><input type="password" name="password" class="form-control" required></div>
                <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">Đăng nhập</button>
            </form>
            <p style="margin-top:14px; font-size:0.9rem;">Chưa có tài khoản? <a href="<?= url('/frontend/auth/register.php') ?>">Đăng ký học viên</a></p>
        </div>
    </div>
</div>
<?php require FE_DIR . '/partials/layout_foot.php'; ?>
