<?php
// Profile fields only. Role/lock/password changes stay in the guarded account-list flow.
require_once __DIR__ . '/../../backend/lib/Auth.php';
require_once __DIR__ . '/../../backend/lib/AuditLog.php';
$user = Auth::requireRole(['admin']);
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') csrf_verify();
$text = static function (array $input, string $key): string {
    return is_string($input[$key] ?? null) ? trim($input[$key]) : '';
};
$fRole = $text($_GET, 'role');
if (!in_array($fRole, ['student', 'teacher', 'admin'], true)) $fRole = '';
$fSearch = $text($_GET, 'q');
$pageValue = $_GET['page'] ?? '1';
$returnPage = is_string($pageValue) && filter_var($pageValue, FILTER_VALIDATE_INT) !== false ? max(1, (int)$pageValue) : 1;
$returnQuery = array_filter(['role' => $fRole, 'q' => $fSearch, 'page' => $returnPage], static fn($value) => $value !== '');
$listUrl = url('/frontend/admin/users.php?' . http_build_query($returnQuery));
$idValue = $_GET['id'] ?? null;
$targetId = is_string($idValue) && preg_match('/\A[1-9][0-9]*\z/', $idValue)
    ? filter_var($idValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
$target = null;
$canEdit = false;
$err = '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    $err = 'Chỉ chấp nhận GET hoặc POST.';
} elseif ($targetId === false) {
    http_response_code(404);
    $err = 'Tài khoản không hợp lệ hoặc không tồn tại.';
} else {
    try {
        $stmt = $pdo->prepare('SELECT id, username, full_name, email, role, is_active, auth_version FROM users WHERE id = ?');
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) {
            http_response_code(404);
            $err = 'Tài khoản không hợp lệ hoặc không tồn tại.';
        } elseif (!AdminPolicy::canManageAccount($user, $target)) {
            http_response_code(403);
            $err = 'Không có quyền thay đổi tài khoản này. Admin chính và tài khoản đang đăng nhập được bảo vệ; chỉ admin chính được quản lý các admin phụ.';
        } else {
            $canEdit = true;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        $err = 'Không thể tải tài khoản. Hãy tải lại trang và thử lại.';
    }
}
$values = [
    'username' => $target['username'] ?? '',
    'full_name' => $target['full_name'] ?? '',
    'email' => $target['email'] ?? '',
];
$formUrl = url('/frontend/admin/user_edit.php?' . http_build_query(['id' => $targetId ?: 0] + $returnQuery));

if ($method === 'POST' && $canEdit) {
    // Never accept target IDs, roles, owner flags, active flags or credentials from POST.
    $allowed = ['csrf_token', 'username', 'full_name', 'email'];
    $validShape = count($_POST) === count($allowed) && !$_FILES;
    foreach ($_POST as $key => $value) {
        if (!in_array($key, $allowed, true) || !is_string($value)) $validShape = false;
    }
    foreach (['username', 'full_name', 'email'] as $key) {
        if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
            $validShape = false;
        } else {
            // Retain only the supported scalar input when redisplaying an error.
            $values[$key] = trim($_POST[$key]);
            if (strpos($_POST[$key], "\0") !== false) $validShape = false;
        }
    }
    $values['email'] = strtolower($values['email']);
    if (!$validShape) {
        $err = 'Dữ liệu không hợp lệ. Chỉ được sửa username, họ tên và email tại đây.';
    } elseif (!preg_match('/\A[a-zA-Z0-9_]{3,50}\z/', $values['username'])) {
        $err = 'Username chỉ gồm chữ, số, gạch dưới (3–50 ký tự).';
    } elseif (!mb_check_encoding($values['full_name'], 'UTF-8') || $values['full_name'] === ''
        || mb_strlen($values['full_name'], 'UTF-8') > 100 || preg_match('/[\x00-\x1F\x7F]/u', $values['full_name'])) {
        $err = 'Họ tên phải có từ 1 đến 100 ký tự và không chứa ký tự điều khiển.';
    } elseif (strlen($values['email']) > 100 || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $err = 'Email không hợp lệ hoặc dài hơn 100 ký tự.';
    }
    if ($err !== '') {
        http_response_code(422);
    } else {
        $ownsTransaction = false;
        try {
            if ($pdo->inTransaction()) throw new LogicException('Account editing requires its own transaction.');
            $pdo->beginTransaction();
            $ownsTransaction = true;
            // Same ascending user-lock order as role changes and class management.
            $ids = [(int)$user['id'], $targetId];
            sort($ids, SORT_NUMERIC);
            $stmt = $pdo->prepare('SELECT id, username, full_name, email, role, is_active, auth_version FROM users WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
            $stmt->execute($ids);
            $lockedUsers = [];
            foreach ($stmt->fetchAll() as $row) $lockedUsers[(int)$row['id']] = $row;
            $actor = $lockedUsers[(int)$user['id']] ?? null;
            $lockedTarget = $lockedUsers[$targetId] ?? null;
            if (!$actor || $actor['role'] !== 'admin' || (int)$actor['is_active'] !== 1
                || !isset($user['auth_version']) || (int)$user['auth_version'] !== (int)$actor['auth_version']) {
                $canEdit = false;
                http_response_code(403);
                throw new DomainException('Quyền tài khoản đã thay đổi. Hãy đăng nhập lại.');
            }
            if (!$lockedTarget) {
                $canEdit = false;
                http_response_code(404);
                throw new DomainException('Tài khoản không hợp lệ hoặc không tồn tại.');
            }
            if (!AdminPolicy::canManageAccount($actor, $lockedTarget)) {
                $canEdit = false;
                http_response_code(403);
                AdminPolicy::assertCanManageAccount($actor, $lockedTarget);
            }
            $target = $lockedTarget;
            $identityChanged = $values['username'] !== $target['username'] || $values['email'] !== $target['email'];
            $changed = $identityChanged || $values['full_name'] !== $target['full_name'];
            if ($changed) {
                // Native collation catches case collisions; unique keys remain the race-safe backstop.
                $stmt = $pdo->prepare('SELECT id FROM users WHERE id <> ? AND (username = ? OR email = ?) LIMIT 1');
                $stmt->execute([$targetId, $values['username'], $values['email']]);
                if ($stmt->fetchColumn()) throw new DomainException('Username hoặc email đã tồn tại.');
                $sql = 'UPDATE users SET username = ?, full_name = ?, email = ?'
                    . ($identityChanged ? ', auth_version = auth_version + 1' : '') . ' WHERE id = ?';
                $pdo->prepare($sql)->execute([$values['username'], $values['full_name'], $values['email'], $targetId]);
                if ($identityChanged) {
                    $pdo->prepare('UPDATE password_reset_otps SET used_at = CURRENT_TIMESTAMP WHERE user_id = ? AND used_at IS NULL')->execute([$targetId]);
                }
                // The event participates in this transaction: failure rolls back fields/version/OTPs.
                AuditLog::record($pdo, $actor, 'user.update', 'user', $targetId, ['source' => 'ui']);
            }
            $pdo->commit();
            $ownsTransaction = false;
            $_SESSION['admin_users_flash'] = ['ok' => true, 'message' => !$changed
                ? 'Thông tin đã đúng như đã lưu; không có thay đổi.'
                : ($identityChanged
                    ? "Đã cập nhật tài khoản #$targetId. Tất cả phiên đăng nhập và mã xác nhận cũ đã bị thu hồi; mật khẩu, lớp học và lịch sử được giữ nguyên."
                    : "Đã cập nhật họ tên tài khoản #$targetId. Phiên đăng nhập, mã xác nhận và lịch sử được giữ nguyên.")];
            header('Location: ' . $listUrl, true, 303);
            exit;
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            $duplicate = $e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062;
            if ($canEdit) http_response_code($e instanceof DomainException || $duplicate ? 422 : 500);
            $err = $e instanceof DomainException ? $e->getMessage() : ($duplicate
                ? 'Username hoặc email đã tồn tại.' : 'Không thể cập nhật tài khoản. Hãy tải lại trang và thử lại.');
        }
    }
}
$pageTitle = 'Sửa tài khoản';
$activeNav = 'admin';
require FE_DIR . '/partials/layout_head.php';
?>
<div style="max-width:680px; margin:28px auto; padding:0 24px;">
    <h2 style="margin-bottom:6px;">Sửa thông tin tài khoản<?= $canEdit ? ' #' . (int)$targetId : '' ?></h2>
    <p style="color:var(--cl-muted); margin-bottom:20px;">Chỉ sửa username, họ tên và email. Thay đổi username hoặc email sẽ thu hồi mọi phiên đăng nhập và mã xác nhận cũ, không đổi mật khẩu hay lịch sử học tập.</p>
    <?php if ($err !== ''): ?><div role="alert" style="background:#fee2e2; color:#b91c1c; padding:12px 18px; border-radius:8px; margin-bottom:16px; font-weight:600;"><?= e($err) ?></div><?php endif; ?>
    <?php if ($canEdit): ?>
        <div style="background:#fff; padding:24px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
            <p style="margin-bottom:16px;">Vai trò hiện tại: <code><?= e($target['role']) ?></code>. Đổi vai trò, khóa và đặt lại mật khẩu tại danh sách tài khoản.</p>
            <form method="POST" action="<?= e($formUrl) ?>">
                <?= csrf_field() ?>
                <div class="form-group"><label for="edit-name">Họ tên</label><input id="edit-name" type="text" name="full_name" class="form-control" required maxlength="100" value="<?= e($values['full_name']) ?>"></div>
                <div class="form-group"><label for="edit-username">Username (chữ/số/_)</label><input id="edit-username" type="text" name="username" class="form-control" required minlength="3" maxlength="50" pattern="[a-zA-Z0-9_]{3,50}" autocomplete="off" value="<?= e($values['username']) ?>"></div>
                <div class="form-group"><label for="edit-email">Email</label><input id="edit-email" type="email" name="email" class="form-control" required maxlength="100" value="<?= e($values['email']) ?>"></div>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary">Lưu thay đổi</button>
                    <a class="btn btn-secondary btn-back" href="<?= e($listUrl) ?>">Hủy / Danh sách</a>
                </div>
            </form>
        </div>
    <?php else: ?>
        <a class="btn btn-secondary btn-back" href="<?= e($listUrl) ?>">Danh sách tài khoản</a>
    <?php endif; ?>
</div>
<?php require FE_DIR . '/partials/layout_foot.php'; ?>
