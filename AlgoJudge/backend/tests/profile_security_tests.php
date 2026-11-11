<?php
/**
 * CLI-only tests against an EXISTING isolated schema; no migrations, .env or real mail.
 * AJ_DB_NAME=algojudge_test_<suffix>, AJ_DB_PORT=<explicit>, optional AJ_DB_USER/AJ_DB_PASS.
 * Every account and limiter digest belongs to this run and is removed in finally.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$name = getenv('AJ_DB_NAME') ?: '';
$portText = getenv('AJ_DB_PORT') ?: '';
if (!preg_match('/^algojudge_test_[a-zA-Z0-9_]+$/D', $name)
    || !preg_match('/^[0-9]{1,5}$/D', $portText) || (int)$portText < 1 || (int)$portText > 65535) {
    fwrite(STDERR, "Refusing: set an existing AJ_DB_NAME=algojudge_test_<suffix> and explicit AJ_DB_PORT.\n");
    exit(1);
}
$pdo = new PDO('mysql:host=127.0.0.1;port=' . (int)$portText . ';dbname=' . $name . ';charset=utf8mb4', getenv('AJ_DB_USER') ?: 'root', getenv('AJ_DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $name) throw new RuntimeException('Unexpected database.');
// Fail before creating fixtures if the profile security migration has not been applied.
$pdo->query('SELECT purpose, context_hash, auth_version FROM password_reset_otps LIMIT 0');
require_once __DIR__ . '/../lib/PasswordReset.php';

// Load the real Auth implementation without executing bootstrap (.env/DB/autoload).
$authSource = file_get_contents(__DIR__ . '/../lib/Auth.php');
$authStart = strpos($authSource, 'final class Auth');
if ($authStart === false) throw new RuntimeException('Auth test loading boundary changed.');
eval(substr($authSource, $authStart));
ob_start();
session_start();
function url(string $path): string { return '/AlgoJudge' . $path; }
function csrf_verify(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) throw new DomainException('CSRF rejected');
}
$prefix = 'pst_' . bin2hex(random_bytes(6));
$key = bin2hex(random_bytes(32));
$password = 'Profile-test-current-2026';
$nextPassword = 'Profile-test-changed-2026';
$ids = [];
$emails = [];
$deliveries = [];
$checks = 0;
$sequence = 0;
$ip = static function () use (&$sequence, $prefix): string { return $prefix . '-ip-' . ++$sequence; };
$binding = bin2hex(random_bytes(24));
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
    echo "PASS $label\n";
};
$fetch = static function (int $id) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?'); $stmt->execute([$id]); return $stmt->fetch();
};
$account = static function (string $role = 'student', bool $active = true) use ($pdo, &$ids, &$emails, $prefix, $password, $fetch): array {
    $username = $prefix . '_' . (count($ids) + 1);
    $email = $username . '@example.test';
    $pdo->prepare('INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $username, $role, $active ? 1 : 0]);
    $id = (int)$pdo->lastInsertId(); $ids[] = $id; $emails[] = $email; return $fetch($id);
};
$sender = static function (string $email, string $code, string $purpose = 'password_reset') use (&$deliveries): void {
    $deliveries[] = ['email' => $email, 'code' => $code, 'purpose' => $purpose];
};
$service = new PasswordReset($pdo, $key, $sender);
$lastCode = static function () use (&$deliveries): string { return $deliveries[count($deliveries) - 1]['code']; };
$issue = static function (array $actor, string $purpose = 'profile_password', string $email = '') use ($service, $password, $binding, $ip, $check): array {
    $challenge = $service->requestProfile($actor, $purpose, $password, $ip(), $binding, $email);
    $check($challenge !== null, 'challenge issued for ' . $purpose);
    return $challenge;
};
$age = static function (int $uid) use ($pdo): void {
    $pdo->prepare('UPDATE password_reset_otps SET sent_at = DATE_SUB(NOW(), INTERVAL 61 SECOND) WHERE user_id = ?')->execute([$uid]);
};
$countRequests = static function (string $email) use ($pdo, $key): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM password_reset_requests WHERE email_digest = ?');
    $stmt->execute([hash_hmac('sha256', 'email:' . $email, $key)]); return (int)$stmt->fetchColumn();
};
$failures = 0;
try {
    $student = $account();
    $before = $fetch((int)$student['id']);
    $challenge = $issue($student);
    $code = $lastCode();
    $check($fetch((int)$student['id']) === $before, 'request changes no identity/password/version');
    $check(array_keys($challenge) === ['id', 'nonce'] && strlen($challenge['nonce']) === 64, 'challenge contains only opaque internal ID and nonce');
    $check($deliveries[0]['email'] === $student['email'], 'recipient derived from current stored email');
    $stmt = $pdo->prepare('SELECT * FROM password_reset_otps WHERE id = ?'); $stmt->execute([$challenge['id']]); $stored = $stmt->fetch();
    $check($stored['otp_hash'] !== $code && strlen($stored['context_hash']) === 64 && $stored['purpose'] === 'profile_password', 'database stores scoped HMACs, not plaintext OTP');
    $wrong = $code === '111111' ? '222222' : '111111';
    $check($service->verifyProfile($student, $challenge, 'profile_password', $wrong, $password, $nextPassword, $binding) === null, 'wrong OTP denied');
    $check($service->verifyProfile($student, $challenge, 'profile_password', $code, 'wrong-password', $nextPassword, $binding) === null, 'current password required again at confirmation');
    $updated = $service->verifyProfile($student, $challenge, 'profile_password', $code, $password, $nextPassword, $binding);
    $fresh = $fetch((int)$student['id']);
    $check($updated !== null && !isset($updated['password_hash']) && password_verify($nextPassword, $fresh['password_hash']) && (int)$fresh['auth_version'] === (int)$before['auth_version'] + 1, 'OTP atomically changes password and version; returned user is safe');
    $_SESSION['user'] = $student;
    $check(Auth::user() === null, 'old authenticated session revoked');
    session_start();
    Auth::login($updated);
    $check(Auth::user() !== null && (int)$_SESSION['user']['auth_version'] === (int)$fresh['auth_version'], 'explicit login creates a fresh authenticated session at the updated version');
    $check($service->verifyProfile($updated, $challenge, 'profile_password', $code, $nextPassword, 'Another-password-2026', $binding) === null, 'used token denied');

    $teacher = $account('teacher');
    $challenge = $issue($teacher); $code = $lastCode();
    $other = $account();
    $check($service->verifyProfile($other, $challenge, 'profile_password', $code, $password, $nextPassword, $binding) === null, 'other user cannot confirm');
    $check($service->verifyProfile($teacher, $challenge, 'profile_email', $code, $password, 'unrelated@example.test', $binding) === null, 'other purpose cannot confirm');
    $check($service->verifyProfile($teacher, $challenge, 'profile_password', $code, $password, $nextPassword, 'other-session') === null, 'other session cannot confirm even with copied challenge');
    $tampered = $challenge; $tampered['nonce'] = str_repeat('a', 64);
    $check($service->verifyProfile($teacher, $tampered, 'profile_password', $code, $password, $nextPassword, $binding) === null, 'other context nonce denied');
    $check($service->verifyProfile($teacher, $challenge, 'profile_password', $code, $password, "Valid-length\0null", $binding) === null, 'NUL new profile password rejected before hashing');
    $check($service->verifyProfile($teacher, $challenge, 'profile_password', $code, $password . "\0", $nextPassword, $binding) === null, 'NUL current profile password rejected');
    $check(!$service->verify($challenge['id'], $teacher['email'], $code, $nextPassword), 'profile OTP cannot be used by forgot-password verifier');
    $check($service->verifyProfile($teacher, $challenge, 'profile_password', $code, $password, $nextPassword, $binding) !== null, 'teacher profile password change succeeds');

    $emailUser = $account();
    $proposed = $prefix . '_new@example.test'; $emails[] = $proposed;
    $challenge = $issue($emailUser, 'profile_email', $proposed); $code = $lastCode();
    $check(end($deliveries)['email'] === $emailUser['email'] && $fetch((int)$emailUser['id'])['email'] === $emailUser['email'], 'email request sends only to CURRENT email and makes no change');
    $check($service->verifyProfile($emailUser, [], 'profile_email', $code, $password, $proposed, $binding) === null, 'email change without challenge denied');
    $check($service->verifyProfile($emailUser, $challenge, 'profile_email', $code, $password, $prefix . '_swapped@example.test', $binding) === null, 'proposed email swap denied by context HMAC');
    $check($service->verifyProfile($emailUser, $challenge, 'profile_email', $code, '', $proposed, $binding) === null, 'email change without current password denied');
    $updated = $service->verifyProfile($emailUser, $challenge, 'profile_email', $code, $password, strtoupper($proposed), $binding);
    $check($updated !== null && $updated['email'] === $proposed && (int)$updated['auth_version'] === (int)$emailUser['auth_version'] + 1, 'bound proposed email normalized and committed with version');
    $check($service->verifyProfile($updated, $challenge, 'profile_email', $code, $password, $proposed, $binding) === null, 'used email token denied');

    $expired = $account(); $challenge = $issue($expired); $code = $lastCode();
    $pdo->prepare('UPDATE password_reset_otps SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?')->execute([$challenge['id']]);
    $check($service->verifyProfile($expired, $challenge, 'profile_password', $code, $password, $nextPassword, $binding) === null, 'database expiry enforced');
    $exhausted = $account(); $challenge = $issue($exhausted); $code = $lastCode();
    $wrong = $code === '111111' ? '222222' : '111111';
    for ($i = 1; $i <= 5; $i++) $check($service->verifyProfile($exhausted, $challenge, 'profile_password', $wrong, $password, $nextPassword, $binding) === null, 'wrong attempt ' . $i);
    $check($service->verifyProfile($exhausted, $challenge, 'profile_password', $code, $password, $nextPassword, $binding) === null, 'five attempts exhaust token');

    foreach (['version', 'role', 'email', 'locked'] as $change) {
        $actor = $account(); $challenge = $issue($actor); $code = $lastCode();
        if ($change === 'version') $pdo->prepare('UPDATE users SET auth_version = auth_version + 1 WHERE id = ?')->execute([$actor['id']]);
        if ($change === 'role') $pdo->prepare("UPDATE users SET role = 'teacher' WHERE id = ?")->execute([$actor['id']]);
        if ($change === 'email') {
            $newEmail = $prefix . '_external_' . $actor['id'] . '@example.test'; $emails[] = $newEmail;
            $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$newEmail, $actor['id']]);
        }
        if ($change === 'locked') $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$actor['id']]);
        $check($service->verifyProfile($actor, $challenge, 'profile_password', $code, $password, $nextPassword, $binding) === null, 'confirm rejects changed ' . $change);
        $check($service->verifyProfile($fetch((int)$actor['id']), $challenge, 'profile_password', $code, $password, $nextPassword, $binding) === null, 'old challenge rejects refreshed actor after ' . $change . ' change');
    }
    $admin = $account('admin'); $locked = $account('student', false);
    foreach ([$admin, $locked] as $actor) {
        $check($service->requestProfile($actor, 'profile_password', $password, $ip(), $binding) === null, 'admin/locked cannot request profile OTP');
        $check($service->request($actor['email'], $ip()) === null, 'admin/locked cannot request recovery OTP');
    }
    $noPassword = $account();
    $check($service->requestProfile($noPassword, 'profile_password', 'wrong', $ip(), $binding) === null, 'wrong current password cannot issue OTP');
    $check($service->requestProfile($noPassword, 'profile_password', $password . "\0", $ip(), $binding) === null, 'NUL current password cannot issue OTP');
    $check($service->requestProfile($noPassword, 'password_reset', $password, $ip(), $binding) === null, 'profile issuer rejects recovery purpose');
    $check($service->requestProfile($noPassword, 'profile_password', $password, $ip(), '') === null, 'profile issuer requires session binding');
    $check($service->requestProfile($noPassword, 'profile_email', $password, $ip(), $binding, $admin['email']) === null, 'existing email cannot be requested');

    $resend = $account(); $challenge = $issue($resend); $oldCode = $lastCode();
    $check($service->requestProfile($resend, 'profile_password', $password, $ip(), $binding) === null, 'profile resend 60-second cooldown');
    $age((int)$resend['id']);
    $replacement = $issue($resend); $newCode = $lastCode();
    $check($replacement['id'] !== $challenge['id'] && $replacement['nonce'] !== $challenge['nonce'], 'resend rotates opaque challenge');
    $check($service->verifyProfile($resend, $challenge, 'profile_password', $oldCode, $password, $nextPassword, $binding) === null, 'resend invalidates old OTP');
    $age((int)$resend['id']);
    $check($service->request($resend['email'], $ip()) === null && $countRequests($resend['email']) === 3, 'three-per-email budget shared with recovery, including cooldown attempts');
    $check($service->verifyProfile($resend, $replacement, 'profile_password', $newCode, $password, $nextPassword, $binding) !== null, 'rate-limited resend leaves current OTP usable');

    $recovery = $account();
    $resetId = $service->request($recovery['email'], $ip()); $resetCode = $lastCode();
    $check($resetId !== null, 'forgot-password request still works');
    $age((int)$recovery['id']);
    $challenge = $issue($recovery); $code = $lastCode();
    $check(!$service->verify($resetId, $recovery['email'], $resetCode, $nextPassword), 'profile issue invalidates prior recovery OTP');
    // A second outstanding OTP simulates another credential flow; success must invalidate all.
    $pdo->prepare("INSERT INTO password_reset_otps (user_id, otp_hash, purpose, expires_at) VALUES (?, ?, 'password_reset', DATE_ADD(NOW(), INTERVAL 10 MINUTE))")
        ->execute([$recovery['id'], hash_hmac('sha256', $recovery['id'] . ':' . $recovery['email'] . ':654321', $key)]);
    $check($service->verifyProfile($recovery, $challenge, 'profile_password', $code, $password, $nextPassword, $binding) !== null, 'profile confirmation succeeds with other pending OTP');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM password_reset_otps WHERE user_id = ? AND used_at IS NULL'); $stmt->execute([$recovery['id']]);
    $check((int)$stmt->fetchColumn() === 0, 'credential success invalidates every other OTP');
    $recoverAgain = $account(); $resetId = $service->request($recoverAgain['email'], $ip()); $resetCode = $lastCode();
    $check($service->verifyProfile($recoverAgain, ['id' => $resetId, 'nonce' => str_repeat('a', 64)], 'profile_password', $resetCode, $password, $nextPassword, $binding) === null, 'recovery OTP cannot confirm profile action');
    $check(!$service->verify($resetId, $recoverAgain['email'], $resetCode, "Valid-length\0null"), 'NUL recovery password rejected before hashing');
    $check($service->verify($resetId, $recoverAgain['email'], $resetCode, $nextPassword), 'forgot-password verification preserved');

    $mailActor = $account(); $challenge = $issue($mailActor); $code = $lastCode(); $age((int)$mailActor['id']);
    $snapshot = $fetch((int)$mailActor['id']); $requestsBefore = $countRequests($mailActor['email']);
    $stmt = $pdo->prepare('SELECT * FROM password_reset_otps WHERE user_id = ? ORDER BY id'); $stmt->execute([$mailActor['id']]); $tokensBefore = $stmt->fetchAll();
    $broken = new PasswordReset($pdo, $key, static function (): void { throw new RuntimeException('Simulated private SMTP internals'); });
    $threw = false;
    try { $broken->requestProfile($mailActor, 'profile_password', $password, $ip(), $binding); } catch (RuntimeException $error) { $threw = true; }
    $stmt->execute([$mailActor['id']]);
    $check($threw && $stmt->fetchAll() === $tokensBefore && $fetch((int)$mailActor['id']) === $snapshot && $countRequests($mailActor['email']) === $requestsBefore, 'SMTP failure leaves credentials, tokens and throttle state unchanged');
    $check($service->verifyProfile($mailActor, $challenge, 'profile_password', $code, $password, $nextPassword, $binding) !== null, 'previous OTP survives failed delivery');
    $threw = false;
    try { new PasswordReset($pdo, 'short', $sender); } catch (InvalidArgumentException $error) { $threw = true; }
    $check($threw, 'missing/short OTP key fails closed');

    $sharedIp = $prefix . '-shared-ip';
    for ($i = 0; $i < 10; $i++) {
        $unknown = $prefix . '_unknown' . $i . '@example.test'; $emails[] = $unknown;
        $service->request($unknown, $sharedIp);
    }
    $ipActor = $account();
    $check($service->requestProfile($ipActor, 'profile_password', $password, $sharedIp, $binding) === null, 'ten-per-IP budget shared with recovery');

    // Execute the actual profile POST handler with isolated DB, fake sender and real Auth.
    // Capture redirects instead of exiting; never load page bootstrap or send real email.
    $source = str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__, 2) . '/frontend/profile.php'));
    $start = strpos($source, '// PROFILE_POST_START'); $end = strpos($source, '// PROFILE_POST_END');
    if ($start === false || $end === false) throw new RuntimeException('Profile POST test boundary changed.');
    $handler = substr($source, $start, $end - $start);
    $redirect = "header('Location: ' . url('/frontend/profile.php'), true, 303);\n    exit;";
    if (substr_count($handler, $redirect) !== 1) throw new RuntimeException('Profile PRG test boundary changed.');
    $handler = str_replace($redirect, 'return $_SESSION[\'profile_flash\'];', $handler);
    $loginRedirect = "header('Location: ' . url('/frontend/auth/login.php?reason=' . \$reason), true, 303);\n                exit;";
    if (substr_count($handler, $loginRedirect) !== 1) throw new RuntimeException('Profile logout redirect test boundary changed.');
    $handler = str_replace($loginRedirect, "return ['ok' => true, 'redirect' => url('/frontend/auth/login.php?reason=' . \$reason)];", $handler);
    $post = static function (array $actor, array $data, ?PasswordReset $provided) use ($pdo, $handler): array {
        if (session_status() !== PHP_SESSION_ACTIVE) { session_id(''); session_start(); }
        $user = $actor; $uid = (int)$actor['id']; $service = $provided; $msg = ''; $msgOk = false;
        $_SESSION['user'] = $actor; $_SESSION['csrf_token'] = 'profile-test-csrf';
        $_POST = array_merge(['csrf_token' => 'profile-test-csrf'], $data);
        $_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['REMOTE_ADDR'] = 'profile-test-post-' . bin2hex(random_bytes(8));
        return eval($handler);
    };
    $postUser = $account(); $unchanged = $fetch((int)$postUser['id']);
    foreach ([
        ['full_name' => 'Legacy name', 'email' => $prefix . '_bypass@example.test', 'current_password' => $password, 'new_password' => $nextPassword, 'confirm_password' => $nextPassword],
        ['action' => 'save_name', 'full_name' => 'Injected name', 'email' => $prefix . '_bypass@example.test'],
        ['action' => 'save_name', 'full_name' => 'Injected name', 'new_password' => $nextPassword],
        ['action' => ['save_name'], 'full_name' => 'Array action'],
        ['action' => 'save_name', 'full_name' => ['Array name']],
        ['action' => 'confirm_email', 'current_password' => $password, 'otp' => '123456', 'proposed_email' => $prefix . '_bypass@example.test'],
    ] as $data) {
        unset($_SESSION['profile_otp']);
        $result = $post($postUser, $data, $service);
        $check(!$result['ok'] && $fetch((int)$postUser['id']) === $unchanged, 'POST rejects legacy/direct/array/email-bypass attempt');
    }
    foreach (['save_name', 'request_password', 'confirm_password', 'request_email', 'confirm_email', 'cancel_otp'] as $action) {
        $adminBefore = $fetch((int)$admin['id']);
        $result = $post($admin, ['action' => $action, 'full_name' => 'Forged admin change'], $service);
        $check(!$result['ok'] && $fetch((int)$admin['id']) === $adminBefore, 'admin forged POST denied: ' . $action);
    }
    $result = $post($postUser, ['action' => 'save_name', 'full_name' => 'Updated test name'], $service);
    $named = $fetch((int)$postUser['id']);
    $check($result['ok'] && $named['full_name'] === 'Updated test name' && $named['email'] === $unchanged['email'] && $named['password_hash'] === $unchanged['password_hash'], 'student retains separate self-name edit');
    $postTeacher = $account('teacher');
    $result = $post($postTeacher, ['action' => 'save_name', 'full_name' => 'Teacher self edit'], $service);
    $check($result['ok'] && $fetch((int)$postTeacher['id'])['full_name'] === 'Teacher self edit', 'teacher retains separate self-name edit');
    $result = $post($postUser, ['action' => 'request_password', 'current_password' => $password], null);
    $check(!$result['ok'], 'unconfigured profile delivery fails closed');
    $csrfDenied = false;
    try { $post($postUser, ['action' => 'save_name', 'full_name' => 'CSRF bypass', 'csrf_token' => ['bad']], $service); } catch (DomainException $error) { $csrfDenied = true; }
    $check($csrfDenied, 'array/missing-valid CSRF denied before handler');

    $result = $post($postUser, ['action' => 'request_password', 'current_password' => $password], $service); $code = $lastCode();
    $check($result['ok'] && isset($_SESSION['profile_otp']['profile_password']), 'profile POST stores opaque challenge after delivery');
    $wrongCode = $code === '111111' ? '222222' : '111111';
    $result = $post($postUser, ['action' => 'confirm_password', 'current_password' => $password, 'otp' => $wrongCode, 'new_password' => $nextPassword, 'confirm_password' => $nextPassword], $service);
    $check(!$result['ok'] && Auth::user() !== null && isset($_SESSION['profile_otp']), 'failed OTP confirmation does not sign user out');
    $_SESSION['ai_pending'] = ['test' => true]; $_SESSION['chat_scope'] = 'test-scope';
    $result = $post($postUser, ['action' => 'confirm_password', 'current_password' => $password, 'otp' => $code, 'new_password' => $nextPassword, 'confirm_password' => $nextPassword], $service);
    $check($result['ok'] && $result['redirect'] === '/AlgoJudge/frontend/auth/login.php?reason=password_changed' && session_status() !== PHP_SESSION_ACTIVE && $_SESSION === [] && Auth::user() === null, 'password POST destroys current session and redirects to login');
    $check(password_verify($nextPassword, $fetch((int)$postUser['id'])['password_hash']), 'password saved before session destruction');
    session_id(''); session_start(); $_SESSION['user'] = $postUser;
    $check(Auth::user() === null, 'other pre-change password session is revoked');
    $postEmail = $prefix . '_post_email@example.test'; $emails[] = $postEmail;
    $result = $post($postTeacher, ['action' => 'request_email', 'current_password' => $password, 'proposed_email' => $postEmail], $service); $code = $lastCode();
    $check($result['ok'] && end($deliveries)['email'] === $postTeacher['email'], 'profile email POST delivers to current address only');
    $result = $post($postTeacher, ['action' => 'confirm_email', 'current_password' => $password, 'otp' => $code, 'proposed_email' => $postEmail], $service);
    $check($result['ok'] && $result['redirect'] === '/AlgoJudge/frontend/auth/login.php?reason=email_changed' && $fetch((int)$postTeacher['id'])['email'] === $postEmail && $_SESSION === [] && session_status() !== PHP_SESSION_ACTIVE && Auth::user() === null, 'teacher email POST commits, destroys session and redirects to login');
    session_id(''); session_start(); $_SESSION['user'] = $postTeacher;
    $check(Auth::user() === null, 'other pre-change email session is revoked');
    $postFailure = $account();
    $result = $post($postFailure, ['action' => 'request_password', 'current_password' => $password], $service);
    $savedChallenge = $_SESSION['profile_otp']; $age((int)$postFailure['id']);
    $result = $post($postFailure, ['action' => 'request_password', 'current_password' => $password], $broken);
    $check(!$result['ok'] && $_SESSION['profile_otp'] === $savedChallenge, 'SMTP failure preserves opaque profile session challenge');
    $check(strpos($result['message'], 'SMTP') === false && strpos($result['message'], $postFailure['email']) === false, 'profile never exposes SMTP exception details or recipient');

    // Recovery must also leave no current session and redirect after the actual OTP verifier succeeds.
    $recoverySource = str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__, 2) . '/frontend/auth/forgot_password.php'));
    $recoveryStart = strpos($recoverySource, "if (\$_SERVER['REQUEST_METHOD'] === 'POST') {");
    $recoveryEnd = strpos($recoverySource, "\$step = !empty(\$_SESSION['password_reset'])");
    if ($recoveryStart === false || $recoveryEnd === false) throw new RuntimeException('Recovery POST boundary changed.');
    $recoveryHandler = substr($recoverySource, $recoveryStart, $recoveryEnd - $recoveryStart);
    $recoveryRedirect = "header('Location: ' . url('/frontend/auth/login.php?reason=password_reset'), true, 303);\n                exit;";
    if (substr_count($recoveryHandler, $recoveryRedirect) !== 1) throw new RuntimeException('Recovery redirect boundary changed.');
    $recoveryHandler = str_replace($recoveryRedirect, "return '/AlgoJudge/frontend/auth/login.php?reason=password_reset';", $recoveryHandler);
    $recoveryUser = $account();
    $resetId = $service->request($recoveryUser['email'], $ip()); $resetCode = $lastCode();
    $_SESSION = ['user' => $recoveryUser, 'csrf_token' => 'recovery-test-csrf', 'profile_otp' => ['old' => true], 'chat_scope' => 'old-chat', 'password_reset' => ['id' => $resetId, 'email' => $recoveryUser['email'], 'attempts' => 0, 'started' => time()]];
    $_POST = ['action' => 'verify', 'csrf_token' => 'recovery-test-csrf', 'otp' => $resetCode === '111111' ? '222222' : '111111', 'password' => $nextPassword, 'confirm_password' => $nextPassword];
    $recoveryPost = static function () use ($service, $recoveryHandler) {
        $configured = true; $msg = ''; $msgOk = false;
        return eval($recoveryHandler);
    };
    $check($recoveryPost() === null && Auth::user() !== null, 'failed recovery OTP leaves session and form active');
    $_POST['otp'] = $resetCode;
    $check($recoveryPost() === '/AlgoJudge/frontend/auth/login.php?reason=password_reset' && $_SESSION === [] && session_status() !== PHP_SESSION_ACTIVE, 'successful recovery clears entire session and redirects to login');
    $recovered = $fetch((int)$recoveryUser['id']);
    $check(password_verify($nextPassword, $recovered['password_hash']) && !password_verify($password, $recovered['password_hash']), 'recovery saves new password and rejects old password');
    session_id(''); session_start(); $_SESSION['user'] = $recoveryUser;
    $check(Auth::user() === null, 'recovery revokes another authenticated session');
    echo "Profile security checks: $checks passed. Disposable fixtures removed.\n";
} catch (Throwable $error) {
    $failures = 1;
    fwrite(STDERR, $error->getMessage() . "\n");
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach (array_unique($emails) as $email) {
        $pdo->prepare('DELETE FROM password_reset_requests WHERE email_digest = ?')->execute([hash_hmac('sha256', 'email:' . $email, $key)]);
    }
    foreach ($ids as $id) {
        $pdo->prepare('DELETE FROM password_reset_otps WHERE user_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM users WHERE id = ? AND username LIKE ?')->execute([$id, $prefix . '%']);
    }
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    ob_end_flush();
}
exit($failures);
