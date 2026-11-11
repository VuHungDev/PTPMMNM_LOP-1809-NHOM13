<?php
require_once __DIR__ . '/../../backend/lib/Auth.php';
require_once __DIR__ . '/../../backend/lib/Mailer.php';
require_once __DIR__ . '/../../backend/lib/PasswordReset.php';

$msg = '';
$msgOk = false;
$key = (string)($_ENV['OTP_HASH_KEY'] ?? getenv('OTP_HASH_KEY') ?: '');
$configured = strlen($key) >= 32 && Mailer::isConfigured();
$service = $configured ? new PasswordReset($pdo, $key, [Mailer::class, 'sendPasswordResetOtp']) : null;
$generic = 'Nếu email thuộc tài khoản đủ điều kiện, mã OTP sẽ được gửi. Vui lòng kiểm tra hộp thư và thư rác; chờ ít nhất 60 giây trước khi gửi lại.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'restart') {
        unset($_SESSION['password_reset']);
    } elseif (!$configured) {
        $msg = 'Chức năng đặt lại mật khẩu chưa được cấu hình. Vui lòng liên hệ quản trị viên.';
    } elseif ($action === 'request' || $action === 'resend') {
        $email = $action === 'resend'
            ? (string)($_SESSION['password_reset']['email'] ?? '')
            : strtolower(trim((string)($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            $msg = 'Vui lòng nhập email hợp lệ.';
        } else {
            // Identical public state for unknown, admin, locked, throttled and eligible email.
            $previous = $_SESSION['password_reset'] ?? [];
            $_SESSION['password_reset'] = [
                'email' => $email,
                'id' => $action === 'resend' && ($previous['email'] ?? '') === $email ? (int)($previous['id'] ?? 0) : 0,
                'started' => time(),
                'attempts' => 0,
            ];
            try {
                $id = $service->request($email, (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
                if ($id !== null) $_SESSION['password_reset']['id'] = $id;
            } catch (Throwable $e) {
                // SMTP exceptions may contain recipient/server details: never echo or log them.
                error_log('AlgoJudge: password reset delivery failed. Check SMTP configuration/server logs.');
            }
            $msg = $generic;
        }
    } elseif ($action === 'verify') {
        $flow = $_SESSION['password_reset'] ?? [];
        $otp = trim((string)($_POST['otp'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if (strlen($password) < 8 || strlen($password) > 72) {
            $msg = 'Mật khẩu cần từ 8 đến 72 byte (ký tự có dấu có thể chiếm nhiều byte).';
        } elseif ($password !== $confirm) {
            $msg = 'Mật khẩu xác nhận không khớp.';
        } else {
            $valid = false;
            if ($flow && (int)$flow['attempts'] < 5 && (int)$flow['started'] > time() - 600) {
                $_SESSION['password_reset']['attempts']++;
                try {
                    $valid = $service->verify((int)$flow['id'], (string)$flow['email'], $otp, $password);
                } catch (Throwable $e) {
                    error_log('AlgoJudge: password reset verification failed.');
                }
            }
            if ($valid) {
                Auth::logout();
                header('Location: ' . url('/frontend/auth/login.php?reason=password_reset'), true, 303);
                exit;
            } else {
                $msg = 'Mã OTP không đúng, đã hết hạn hoặc đã hết lượt thử. Bạn có thể yêu cầu mã mới.';
            }
        }
    }
}
$step = !empty($_SESSION['password_reset']) ? 'verify' : 'request';
$pageTitle = 'Quên mật khẩu';
require FE_DIR . '/partials/layout_head.php';
?>
<div class="auth-page"><div class="auth-form-wrap"><div class="auth-card">
    <h2 style="margin-bottom:16px;">Đặt lại mật khẩu</h2>
    <?php if (!$configured): ?><p role="status" style="margin-bottom:14px;">Chức năng email chưa được cấu hình. Quản trị viên cần cài thư viện PHPMailer và điền cấu hình SMTP, OTP_HASH_KEY.</p><?php endif; ?>
    <?php if ($msg): ?><div role="status" style="background:<?= $msgOk ? '#dcfce7; color:#15803d' : '#f1f5f9; color:#334155' ?>; padding:10px 14px; border-radius:8px; margin-bottom:14px;"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($step === 'request'): ?>
        <p>Nhập email tài khoản học viên hoặc giáo viên. OTP có hiệu lực 10 phút, tối đa 5 lần thử. Không áp dụng cho tài khoản quản trị.</p>
        <form method="POST">
            <?= csrf_field() ?><input type="hidden" name="action" value="request">
            <div class="form-group"><label for="reset-email">Email</label><input id="reset-email" type="email" name="email" class="form-control" autocomplete="email" required maxlength="100"></div>
            <button class="btn btn-primary" style="width:100%; justify-content:center;" <?= !$configured ? 'disabled' : '' ?>>Gửi mã OTP</button>
        </form>
    <?php else: ?>
        <form method="POST">
            <?= csrf_field() ?><input type="hidden" name="action" value="verify">
            <div class="form-group"><label for="reset-otp">Mã OTP</label><input id="reset-otp" name="otp" class="form-control" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></div>
            <div class="form-group"><label for="reset-password">Mật khẩu mới</label><input id="reset-password" type="password" name="password" class="form-control" autocomplete="new-password" minlength="8" maxlength="72" required></div>
            <div class="form-group"><label for="reset-confirm">Nhập lại mật khẩu</label><input id="reset-confirm" type="password" name="confirm_password" class="form-control" autocomplete="new-password" minlength="8" maxlength="72" required></div>
            <button class="btn btn-primary" style="width:100%; justify-content:center;">Xác minh và đặt mật khẩu</button>
        </form>
        <form method="POST" style="margin-top:10px;">
            <?= csrf_field() ?><button class="btn btn-secondary" name="action" value="resend">Gửi lại mã OTP</button>
            <button class="btn btn-secondary" name="action" value="restart">Dùng email khác</button>
        </form>
    <?php endif; ?>
    <p style="margin-top:14px;"><a class="btn btn-secondary btn-back" href="<?= url('/frontend/auth/login.php') ?>"><span aria-hidden="true">←</span> Quay lại đăng nhập</a></p>
</div></div></div>
<?php require FE_DIR . '/partials/layout_foot.php'; ?>
