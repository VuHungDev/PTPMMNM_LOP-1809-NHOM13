<?php
// Separate self-service actions; administrators have a read-only identity and their own audit history.
require_once __DIR__ . '/../backend/lib/Auth.php';
require_once __DIR__ . '/../backend/lib/Mailer.php';
require_once __DIR__ . '/../backend/lib/PasswordReset.php';
require_once __DIR__ . '/../backend/lib/AuditLog.php';
$user = Auth::requireLogin();
$uid = (int)$user['id'];
$isAdmin = $user['role'] === 'admin';
$flash = $_SESSION['profile_flash'] ?? [];
unset($_SESSION['profile_flash']);
$msg = is_string($flash['message'] ?? null) ? $flash['message'] : '';
$msgOk = ($flash['ok'] ?? false) === true;
$key = (string)($_ENV['OTP_HASH_KEY'] ?? getenv('OTP_HASH_KEY') ?: '');
$configured = !$isAdmin && strlen($key) >= 32 && Mailer::isConfigured();
$service = $configured ? new PasswordReset($pdo, $key, [Mailer::class, 'sendProfileOtp']) : null;

// PROFILE_POST_START: kept separate from display queries so every write ends in PRG.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        if (!in_array($user['role'], ['student', 'teacher'], true)) throw new DomainException('Thông tin quản trị viên chỉ được xem tại trang này.');
        // Explicit field allowlists reject legacy direct updates and array-shaped inputs.
        $fields = [
            'save_name' => ['full_name'],
            'request_password' => ['current_password'],
            'confirm_password' => ['current_password', 'otp', 'new_password', 'confirm_password'],
            'request_email' => ['current_password', 'proposed_email'],
            'confirm_email' => ['current_password', 'otp', 'proposed_email'],
            'cancel_otp' => [],
        ];
        $action = $_POST['action'] ?? null;
        if (!is_string($action) || !isset($fields[$action])) throw new DomainException('Yêu cầu không hợp lệ. Vui lòng dùng đúng biểu mẫu.');
        $allowed = array_merge(['action', 'csrf_token'], $fields[$action]);
        foreach ($_POST as $field => $value) {
            if (!in_array($field, $allowed, true) || !is_string($value)) throw new DomainException('Dữ liệu biểu mẫu không hợp lệ.');
        }
        foreach ($fields[$action] as $field) {
            if (!isset($_POST[$field])) throw new DomainException('Vui lòng điền đầy đủ thông tin.');
        }
        if ($action === 'save_name') {
            $fullName = trim($_POST['full_name']);
            if ($fullName === '' || mb_strlen($fullName) > 100) throw new DomainException('Họ tên phải có từ 1 đến 100 ký tự.');
            $pdo->beginTransaction();
            $stmtCurrent = $pdo->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
            $stmtCurrent->execute([$uid]);
            $current = $stmtCurrent->fetch();
            if (!$current || !(int)$current['is_active'] || !in_array($current['role'], ['student', 'teacher'], true)
                || $current['role'] !== $user['role'] || (int)$current['auth_version'] !== (int)$user['auth_version']) {
                throw new DomainException('Phiên đăng nhập đã thay đổi. Hãy đăng nhập lại.');
            }
            $pdo->prepare('UPDATE users SET full_name = ? WHERE id = ?')->execute([$fullName, $uid]);
            $pdo->commit();
            $msg = 'Đã cập nhật họ tên.';
        } elseif ($action === 'cancel_otp') {
            unset($_SESSION['profile_otp']);
            $msg = 'Đã hủy xác nhận trong phiên này.';
        } else {
            if (!$service) throw new DomainException('Chức năng email hiện chưa sẵn sàng. Vui lòng liên hệ quản trị viên.');
            $purpose = in_array($action, ['request_email', 'confirm_email'], true) ? 'profile_email' : 'profile_password';
            $currentPassword = $_POST['current_password'];
            if ($currentPassword === '' || strlen($currentPassword) > 72 || strpos($currentPassword, "\0") !== false) throw new DomainException('Vui lòng nhập mật khẩu hiện tại hợp lệ.');
            $value = $purpose === 'profile_email' ? strtolower(trim($_POST['proposed_email'])) : ($_POST['new_password'] ?? '');
            if ($purpose === 'profile_email' && (!filter_var($value, FILTER_VALIDATE_EMAIL) || strlen($value) > 100)) throw new DomainException('Email mới không hợp lệ.');
            if (in_array($action, ['request_password', 'request_email'], true)) {
                $challenge = $service->requestProfile($user, $purpose, $currentPassword, (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), session_id(), $value);
                if ($challenge === null) throw new DomainException('Chưa thể gửi mã. Kiểm tra mật khẩu hiện tại và email mới; chờ ít nhất 60 giây giữa các lần gửi, tối đa 3 lần/email và 10 lần/IP mỗi giờ (dùng chung với quên mật khẩu).');
                // Replace only after successful delivery; an SMTP failure leaves the old state intact.
                $_SESSION['profile_otp'] = [$purpose => $challenge];
                $msg = 'Đã gửi OTP đến email HIỆN TẠI của bạn. Mã có hiệu lực 10 phút, tối đa 5 lần thử. Mã cũ đã hết hiệu lực.';
            } else {
                if ($purpose === 'profile_password') {
                    if (strlen($value) < 8 || strlen($value) > 72 || strpos($value, "\0") !== false) throw new DomainException('Mật khẩu mới cần từ 8 đến 72 byte và không chứa ký tự rỗng.');
                    if ($value !== $_POST['confirm_password']) throw new DomainException('Mật khẩu xác nhận không khớp.');
                }
                $challenge = $_SESSION['profile_otp'][$purpose] ?? [];
                $updated = $service->verifyProfile($user, is_array($challenge) ? $challenge : [], $purpose, trim($_POST['otp']), $currentPassword, $value, session_id());
                if ($updated === null) throw new DomainException('Không thể xác nhận. Kiểm tra mật khẩu hiện tại, OTP và email mới đã yêu cầu; mã có thể hết hạn hoặc hết lượt thử.');
                // The committed auth_version revokes every old session, including this one.
                Auth::logout();
                $reason = $purpose === 'profile_email' ? 'email_changed' : 'password_changed';
                header('Location: ' . url('/frontend/auth/login.php?reason=' . $reason), true, 303);
                exit;
            }
        }
        $msgOk = true;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = $error instanceof DomainException ? $error->getMessage() : 'Không thể cập nhật lúc này. Vui lòng thử lại sau.';
        $msgOk = false;
    }
    $_SESSION['profile_flash'] = ['message' => $msg, 'ok' => $msgOk];
    header('Location: ' . url('/frontend/profile.php'), true, 303);
    exit;
}
// PROFILE_POST_END

$stmtU = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmtU->execute([$uid]);
$me = $stmtU->fetch();
$isStudent = $me['role'] === 'student';
$isAdmin = $me['role'] === 'admin';
$pageValue = $_GET['page'] ?? '1';
$page = is_string($pageValue) && preg_match('/^[1-9][0-9]{0,8}$/D', $pageValue) ? (int)$pageValue : 1;
$audit = null;
$auditUnavailable = false;
if ($isAdmin) {
    try {
        $audit = AuditLog::page($pdo, $uid, $page, 20);
        $page = (int)$audit['page'];
        $totalPages = (int)$audit['totalPages'];
        $baseQuery = [];
    } catch (Throwable $error) {
        $auditUnavailable = true;
    }
}
// Never render raw audit payloads, arbitrary target text, or unknown metadata fields.
$auditNumber = static function ($value): ?string {
    if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]{1,10}$/D', (string)$value)) return null;
    return (string)(int)$value;
};
$auditDetails = static function ($raw) use ($auditNumber): string {
    $metadata = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($metadata)) return '';
    $labels = ['teacher_id' => 'Giáo viên', 'student_id' => 'Học viên', 'version_number' => 'Phiên bản', 'submission_id' => 'Bài nộp', 'problem_id' => 'Bài tập', 'previous_run_id' => 'Lượt chấm trước', 'count' => 'Số lượng'];
    $details = [];
    foreach ($labels as $key => $label) {
        $value = $auditNumber($metadata[$key] ?? null);
        if ($value !== null) $details[] = $label . ': ' . $value;
    }
    $roles = ['student' => 'Học viên', 'teacher' => 'Giảng viên', 'admin' => 'Quản trị viên'];
    foreach (['previous_role' => 'Vai trò cũ', 'role' => 'Vai trò'] as $field => $label) {
        $role = $metadata[$field] ?? null;
        if (is_string($role) && isset($roles[$role])) $details[] = $label . ': ' . $roles[$role];
    }
    $source = $metadata['source'] ?? null;
    $sources = ['ui' => 'Giao diện', 'api' => 'API', 'ai' => 'Trợ lý AI'];
    if (is_string($source) && isset($sources[$source])) $details[] = 'Nguồn: ' . $sources[$source];
    return implode(' · ', $details);
};
$perPage = 10;
$myRank = null;
$solved = 0;
$totalProblems = 0;
$pct = 0;
$perProblem = [];
$recent = [];
if ($isStudent) {
    $stmtR = $pdo->prepare("SELECT COUNT(*) + 1 FROM users WHERE role = 'student' AND xp_points > (SELECT xp_points FROM users WHERE id = ?)");
    $stmtR->execute([$uid]);
    $myRank = (int)$stmtR->fetchColumn();
    $totalProblems = (int)$pdo->query("SELECT COUNT(*) FROM problems WHERE is_active = 1")->fetchColumn();
    $stmtS = $pdo->prepare("SELECT COUNT(DISTINCT s.problem_id) FROM submissions s JOIN problems p ON p.id = s.problem_id WHERE s.user_id = ? AND s.final_verdict = 'AC' AND p.is_active = 1");
    $stmtS->execute([$uid]);
    $solved = (int)$stmtS->fetchColumn();
    $pct = $totalProblems > 0 ? round($solved / $totalProblems * 100) : 0;
    $stmtCount = $pdo->query("SELECT COUNT(*) FROM problems WHERE is_active = 1");
    $totalProgressRows = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, (int)ceil($totalProgressRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $stmtP = $pdo->prepare("
        SELECT p.id, p.title, p.difficulty,
               (SELECT s.final_verdict FROM submissions s WHERE s.user_id = ? AND s.problem_id = p.id ORDER BY CASE s.final_verdict WHEN 'AC' THEN 0 ELSE 1 END, s.id DESC LIMIT 1) AS best,
               (SELECT COUNT(*) FROM submissions s WHERE s.user_id = ? AND s.problem_id = p.id) AS tries
        FROM problems p WHERE p.is_active = 1 ORDER BY p.id ASC LIMIT $perPage OFFSET $offset
    ");
    $stmtP->execute([$uid, $uid]);
    $perProblem = $stmtP->fetchAll();
    $baseQuery = [];
}
if (!$isAdmin) {
    $stmtL = $pdo->prepare("
        SELECT s.id, s.language, s.final_verdict, s.final_score, s.pass_count, s.total_cases, s.created_at, p.title
        FROM submissions s JOIN problems p ON p.id = s.problem_id
        WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 10
    ");
    $stmtL->execute([$uid]);
    $recent = $stmtL->fetchAll();
}

$pageTitle = $isAdmin ? 'Hồ sơ quản trị viên' : 'Hồ sơ của tôi';
$activeNav = 'profile';
require FE_DIR . '/partials/layout_head.php';
?>
<style>
.profile-page { max-width:1100px; margin:28px auto; padding:0 24px; }
.profile-summary { display:flex; flex-wrap:wrap; gap:20px; align-items:center; }
.profile-identity { flex:1 1 160px; min-width:0; overflow-wrap:anywhere; }
.profile-stats { display:flex; flex-wrap:wrap; gap:16px; text-align:center; margin-left:auto; }
.profile-stats > div { min-width:0; }
.profile-card { background:#fff; border-radius:12px; padding:20px 24px; box-shadow:0 2px 8px rgba(0,0,0,0.05); margin-bottom:20px; min-width:0; }
.profile-card h3 { margin-bottom:12px; }
.profile-card p { color:var(--cl-muted); margin-bottom:14px; overflow-wrap:anywhere; }
.profile-fields { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr)); gap:12px; }
.profile-fields > div { min-width:0; }
.profile-actions { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-top:12px; }
.profile-actions .btn { white-space:normal; text-align:center; max-width:100%; }
.profile-confirm { border-top:1px solid var(--cl-border); margin-top:20px; padding-top:20px; }
.profile-info { display:grid; grid-template-columns:minmax(100px,1fr) minmax(0,3fr); gap:10px 18px; }
.profile-info dt { color:var(--cl-muted); }
.profile-info dd { margin:0; overflow-wrap:anywhere; }
.profile-audit-scroll { overflow-x:auto; }
.profile-audit { width:100%; border-collapse:collapse; font-size:0.9rem; }
.profile-audit th, .profile-audit td { padding:12px 8px; text-align:left; border-bottom:1px solid var(--cl-border); overflow-wrap:anywhere; }
@media (max-width:640px) {
    .profile-card { padding:18px 16px; }
    .profile-info { grid-template-columns:1fr; gap:4px; }
    .profile-info dd { margin-bottom:10px; }
    .profile-actions .btn { flex:1 1 100%; justify-content:center; }
    .profile-page { padding:0 16px; }
    .profile-stats { flex-basis:100%; margin-left:0; justify-content:space-around; }
    .profile-stats > div { flex:1; overflow-wrap:anywhere; }
}
</style>
<div class="profile-page">
    <div class="profile-summary" style="background:#fff; border-radius:12px; padding:24px; box-shadow:0 2px 8px rgba(0,0,0,0.05); margin-bottom:20px;">
        <div style="flex:0 0 72px; width:72px; height:72px; border-radius:50%; background:linear-gradient(135deg, #2c31cf, #0ea5e9); color:#fff; display:flex; align-items:center; justify-content:center; font-size:1.8rem; font-weight:800;">
            <?= e(mb_strtoupper(mb_substr($me['username'], 0, 1))) ?>
        </div>
        <div class="profile-identity">
            <h2 style="margin-bottom:2px;"><?= e($me['full_name']) ?></h2>
            <div style="color:var(--cl-muted); font-size:0.9rem;"><code><?= e($me['username']) ?></code> · <?= e(['student' => 'Học viên', 'teacher' => 'Giáo viên', 'admin' => 'Quản trị viên'][$me['role']]) ?></div>
        </div>
        <?php if ($isStudent): ?>
        <div class="profile-stats">
            <div><div style="font-size:1.5rem; font-weight:800;"><?= (int)$me['xp_points'] ?></div><div style="font-size:0.8rem; color:var(--cl-muted);">XP</div></div>
            <div><div style="font-size:1.5rem; font-weight:800;">#<?= $myRank ?></div><div style="font-size:0.8rem; color:var(--cl-muted);">Xếp hạng</div></div>
            <div><div style="font-size:1.5rem; font-weight:800;"><?= $solved ?>/<?= $totalProblems ?></div><div style="font-size:0.8rem; color:var(--cl-muted);">Bài đã giải</div></div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($msg): ?><div role="status" style="background:<?= $msgOk ? '#dcfce7; color:#15803d' : '#fee2e2; color:#b91c1c' ?>; padding:12px 18px; border-radius:8px; margin-bottom:16px;"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($isAdmin): ?>
    <section class="profile-card">
        <h3>Thông tin quản trị viên</h3>
        <p>Hồ sơ quản trị viên chỉ hiển thị thông tin tài khoản, không hỗ trợ thay đổi thông tin đăng nhập tại đây.</p>
        <dl class="profile-info">
            <dt>Họ tên</dt><dd><?= e($me['full_name']) ?></dd>
            <dt>Tên đăng nhập</dt><dd><?= e($me['username']) ?></dd>
            <dt>Email</dt><dd><?= e($me['email']) ?></dd>
            <dt>Vai trò</dt><dd>Quản trị viên</dd>
            <dt>Ngày tạo</dt><dd><?= e($me['created_at']) ?></dd>
        </dl>
    </section>
    <section class="profile-card">
        <h3>Lịch sử hoạt động quản trị</h3>
        <p>Ghi nhận các thao tác thành công của bạn kể từ khi tính năng nhật ký được triển khai; không khôi phục lịch sử trước đó.</p>
        <?php if ($auditUnavailable): ?>
            <p role="status">Nhật ký hoạt động hiện chưa sẵn sàng. Vui lòng thử lại sau hoặc kiểm tra cập nhật hệ thống.</p>
        <?php elseif (!$audit['rows']): ?>
            <p>Chưa ghi nhận hoạt động quản trị của bạn.</p>
        <?php else: ?>
            <p>Chỉ hiển thị hoạt động do tài khoản của bạn thực hiện.</p>
            <div class="profile-audit-scroll"><table class="profile-audit">
                <thead><tr><th>Thời gian</th><th>Hoạt động</th><th>Đối tượng</th><th>Chi tiết</th></tr></thead>
                <tbody><?php foreach ($audit['rows'] as $entry):
                    $target = $auditNumber($entry['target_id'] ?? null);
                    $targetLabel = ['user' => 'Tài khoản', 'class' => 'Lớp học', 'problem' => 'Bài tập', 'submission' => 'Bài nộp', 'run' => 'Lượt chấm'][$entry['target_type'] ?? ''] ?? 'Đối tượng';
                ?>
                <tr>
                    <td><?= e($entry['created_at']) ?></td>
                    <td><?= e(AuditLog::label($entry['action'])) ?></td>
                    <td><?= $target === null ? '—' : e($targetLabel . ' #' . $target) ?></td>
                    <td><?= e($auditDetails($entry['metadata'] ?? null)) ?></td>
                </tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <?php require FE_DIR . '/partials/pagination.php'; ?>
        <?php endif; ?>
    </section>
    <?php else: ?>
    <section class="profile-card">
        <h3>Thông tin tài khoản</h3>
        <p>Email hiện tại: <strong><?= e($me['email']) ?></strong></p>
        <form method="POST">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_name">
            <div class="form-group"><label for="profile-name">Họ tên</label><input id="profile-name" class="form-control" name="full_name" maxlength="100" value="<?= e($me['full_name']) ?>" autocomplete="name" required></div>
            <div class="profile-actions"><button class="btn btn-primary">Lưu họ tên</button></div>
        </form>
    </section>
    <p role="status" class="profile-card">Sau khi đổi mật khẩu hoặc email thành công, tất cả phiên đăng nhập sẽ hết hiệu lực, kể cả phiên hiện tại. Bạn sẽ được chuyển về trang đăng nhập để đăng nhập lại.</p>
    <?php if (!$configured): ?><p role="status" class="profile-card">Chức năng email hiện chưa sẵn sàng. Đổi email và mật khẩu được tạm khóa; vui lòng liên hệ quản trị viên.</p><?php endif; ?>
    <section class="profile-card">
        <h3>Đổi mật khẩu bằng OTP</h3>
        <p>Bắt buộc nhập mật khẩu hiện tại khi gửi mã và khi xác nhận. OTP gửi đến email hiện tại, hết hạn sau 10 phút và tối đa 5 lần thử. Chờ ít nhất 60 giây trước khi gửi lại; tối đa 3 yêu cầu/email và 10 yêu cầu/IP mỗi giờ, dùng chung với quên mật khẩu.</p>
        <form method="POST">
            <?= csrf_field() ?><input type="hidden" name="action" value="request_password">
            <div class="form-group"><label for="password-request-current">Mật khẩu hiện tại</label><input id="password-request-current" type="password" class="form-control" name="current_password" autocomplete="current-password" maxlength="72" required></div>
            <div class="profile-actions"><button class="btn btn-secondary" <?= !$configured ? 'disabled' : '' ?>><?= isset($_SESSION['profile_otp']['profile_password']) ? 'Gửi lại OTP đổi mật khẩu' : 'Gửi OTP đổi mật khẩu' ?></button></div>
        </form>
        <?php if (isset($_SESSION['profile_otp']['profile_password'])): ?>
        <form method="POST" class="profile-confirm">
            <?= csrf_field() ?><input type="hidden" name="action" value="confirm_password">
            <div class="profile-fields">
                <div class="form-group"><label for="password-confirm-current">Mật khẩu hiện tại</label><input id="password-confirm-current" type="password" class="form-control" name="current_password" autocomplete="current-password" maxlength="72" required></div>
                <div class="form-group"><label for="password-otp">Mã OTP</label><input id="password-otp" class="form-control" name="otp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></div>
                <div class="form-group"><label for="password-new">Mật khẩu mới (8–72 byte)</label><input id="password-new" type="password" class="form-control" name="new_password" autocomplete="new-password" minlength="8" maxlength="72" required></div>
                <div class="form-group"><label for="password-confirm">Nhập lại mật khẩu mới</label><input id="password-confirm" type="password" class="form-control" name="confirm_password" autocomplete="new-password" minlength="8" maxlength="72" required></div>
            </div>
            <div class="profile-actions"><button class="btn btn-primary" <?= !$configured ? 'disabled' : '' ?>>Xác nhận đổi mật khẩu</button></div>
        </form>
        <?php endif; ?>
    </section>
    <section class="profile-card">
        <h3>Đổi email bằng OTP</h3>
        <p>Mã xác nhận chỉ gửi đến <strong>email HIỆN TẠI</strong>, không gửi đến email mới. Cần mật khẩu hiện tại ở cả hai bước. Khi xác nhận, nhập lại đúng email mới đã yêu cầu; thay đổi email mới cần yêu cầu mã khác.</p>
        <form method="POST">
            <?= csrf_field() ?><input type="hidden" name="action" value="request_email">
            <div class="profile-fields">
                <div class="form-group"><label for="email-request-current">Mật khẩu hiện tại</label><input id="email-request-current" type="password" class="form-control" name="current_password" autocomplete="current-password" maxlength="72" required></div>
                <div class="form-group"><label for="email-proposed">Email mới</label><input id="email-proposed" type="email" class="form-control" name="proposed_email" autocomplete="email" maxlength="100" required></div>
            </div>
            <div class="profile-actions"><button class="btn btn-secondary" <?= !$configured ? 'disabled' : '' ?>><?= isset($_SESSION['profile_otp']['profile_email']) ? 'Gửi lại OTP đổi email' : 'Gửi OTP đổi email' ?></button></div>
        </form>
        <?php if (isset($_SESSION['profile_otp']['profile_email'])): ?>
        <form method="POST" class="profile-confirm">
            <?= csrf_field() ?><input type="hidden" name="action" value="confirm_email">
            <div class="profile-fields">
                <div class="form-group"><label for="email-confirm-current">Mật khẩu hiện tại</label><input id="email-confirm-current" type="password" class="form-control" name="current_password" autocomplete="current-password" maxlength="72" required></div>
                <div class="form-group"><label for="email-otp">Mã OTP</label><input id="email-otp" class="form-control" name="otp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></div>
                <div class="form-group"><label for="email-confirm-proposed">Nhập lại email mới đã yêu cầu</label><input id="email-confirm-proposed" type="email" class="form-control" name="proposed_email" autocomplete="email" maxlength="100" required></div>
            </div>
            <div class="profile-actions"><button class="btn btn-primary" <?= !$configured ? 'disabled' : '' ?>>Xác nhận đổi email</button></div>
        </form>
        <?php endif; ?>
    </section>
    <?php if (!empty($_SESSION['profile_otp'])): ?>
    <form method="POST" class="profile-actions" style="margin-bottom:20px;">
        <?= csrf_field() ?><input type="hidden" name="action" value="cancel_otp"><button class="btn btn-back">Hủy xác nhận OTP trong phiên này</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($isStudent): ?>
    <div style="background:#fff; border-radius:12px; padding:20px 24px; box-shadow:0 2px 8px rgba(0,0,0,0.05); margin-bottom:20px;">
        <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
            <strong>Tiến độ hoàn thành</strong><span><?= $pct ?>%</span>
        </div>
        <div style="background:#f1f5f9; border-radius:999px; height:14px; overflow:hidden;">
            <div style="background:linear-gradient(90deg, #2c31cf, #38bdf8); height:14px; width:<?= $pct ?>%;"></div>
        </div>
    </div>

    <?php endif; ?>
    <?php if (!$isAdmin): ?>
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr)); gap:20px;">
        <?php if ($isStudent): ?>
        <div style="background:#fff; border-radius:12px; padding:20px 24px; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
            <h3 style="margin-bottom:12px;">Tiến độ từng bài</h3>
            <table style="width:100%; border-collapse:collapse; font-size:0.88rem;">
                <?php foreach ($perProblem as $p): ?>
                <tr style="border-bottom:1px solid var(--cl-border);">
                    <td style="padding:8px 4px;"><a href="<?= url('/frontend/workspace.php?id=' . (int)$p['id']) ?>" style="color:var(--cl-text); font-weight:600;"><?= e($p['title']) ?></a></td>
                    <td style="padding:8px 4px; text-align:right;">
                        <?php if ($p['best'] === null): ?>
                            <span class="badge" style="background:#f1f5f9; color:#64748b;">Chưa làm</span>
                        <?php elseif ($p['best'] === 'AC'): ?>
                            <span class="badge verdict-AC">✔ AC (<?= (int)$p['tries'] ?> lần nộp)</span>
                        <?php else: ?>
                            <span class="badge verdict-<?= e($p['best']) ?>"><?= e($p['best']) ?> (<?= (int)$p['tries'] ?> lần nộp)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$perProblem): ?><tr><td colspan="2" style="padding:14px; color:var(--cl-muted);">Chưa có bài tập.</td></tr><?php endif; ?>
            </table>
            <?php require FE_DIR . '/partials/pagination.php'; ?>
        </div>
        <?php endif; ?>
        <div style="background:#fff; border-radius:12px; padding:20px 24px; box-shadow:0 2px 8px rgba(0,0,0,0.05); align-self:start;">
            <h3 style="margin-bottom:12px;">10 bài nộp gần nhất</h3>
            <table style="width:100%; border-collapse:collapse; font-size:0.88rem;">
                <?php foreach ($recent as $s): ?>
                <tr style="border-bottom:1px solid var(--cl-border);">
                    <td style="padding:8px 4px; font-weight:700;">#<?= (int)$s['id'] ?></td>
                    <td style="padding:8px 4px;"><?= e($s['title']) ?><br><small style="color:var(--cl-muted);"><code><?= e($s['language']) ?></code> · <?= e($s['created_at']) ?></small></td>
                    <td style="padding:8px 4px; text-align:right;"><span class="badge verdict-<?= e($s['final_verdict']) ?>"><?= e($s['final_verdict']) ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$recent): ?>
                <tr><td style="padding:16px; color:var(--cl-muted);">Chưa có bài nộp nào.</td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php require FE_DIR . '/partials/layout_foot.php'; ?>
