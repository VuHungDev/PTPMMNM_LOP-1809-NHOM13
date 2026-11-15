<?php
// Read-only account details. Viewing does not grant permission to mutate a protected account.
require_once __DIR__ . '/../../backend/lib/Auth.php';
$user = Auth::requireRole(['admin']);
header('Cache-Control: private, no-store');
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
$summary = null;
$canManage = false;
$err = '';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    $err = 'Chỉ chấp nhận GET. Trang này chỉ dùng để xem thông tin.';
} elseif ($targetId === false) {
    http_response_code(404);
    $err = 'Tài khoản không hợp lệ hoặc không tồn tại.';
} else {
    try {
        // Explicit safe projection: never load credentials, recovery data or submission source.
        $stmt = $pdo->prepare('SELECT u.id, u.username, u.full_name, u.email, u.role, u.is_active, u.created_at
            FROM users u WHERE u.id = ? LIMIT 1');
        $stmt->execute([$targetId]);
        $target = $stmt->fetch() ?: null;
        if ($target === null) {
            http_response_code(404);
            $err = 'Tài khoản không hợp lệ hoặc không tồn tại.';
        } else {
            // Summaries follow the selected account's live role, never the viewer or list filter.
            $summarySql = null;
            if ($target['role'] === 'teacher') {
                $summarySql = "SELECT
                    (SELECT COUNT(*) FROM classes c WHERE c.owner_teacher_id = u.id) AS owned_class_count,
                    (SELECT GROUP_CONCAT(CONCAT(c.name, ' (#', c.id, ')') ORDER BY c.name, c.id SEPARATOR ', ') FROM classes c WHERE c.owner_teacher_id = u.id) AS owned_class_names,
                    (SELECT COUNT(DISTINCT cm.user_id) FROM class_members cm JOIN classes c ON c.id = cm.class_id JOIN users student ON student.id = cm.user_id WHERE c.owner_teacher_id = u.id AND cm.status = 'active' AND student.role = 'student') AS student_count
                    FROM users u WHERE u.id = ? AND u.role = 'teacher' LIMIT 1";
            } elseif ($target['role'] === 'student') {
                $summarySql = "SELECT u.xp_points,
                    (SELECT COUNT(*) FROM submissions s WHERE s.user_id = u.id) AS sub_count,
                    (SELECT COUNT(DISTINCT s.problem_id) FROM submissions s WHERE s.user_id = u.id AND s.final_verdict = 'AC') AS solved_count,
                    (SELECT GROUP_CONCAT(CONCAT(c.name, ' (#', c.id, ')') ORDER BY c.name, c.id SEPARATOR ', ') FROM class_members cm JOIN classes c ON c.id = cm.class_id WHERE cm.user_id = u.id AND cm.status = 'active') AS class_names
                    FROM users u WHERE u.id = ? AND u.role = 'student' LIMIT 1";
            }
            if ($summarySql !== null) {
                $stmt = $pdo->prepare($summarySql);
                $stmt->execute([$targetId]);
                $summary = $stmt->fetch();
                // A concurrent deletion/role change must not leave a misleading partial profile.
                if (!$summary) throw new RuntimeException('Account summary is no longer available.');
            }
            $canManage = AdminPolicy::canManageAccount($user, $target);
        }
    } catch (Throwable $e) {
        $target = null;
        $summary = null;
        http_response_code(500);
        $err = 'Không thể tải thông tin tài khoản. Hãy tải lại trang và thử lại.';
    }
}
$displayText = static function ($value, string $fallback = 'Chưa cập nhật'): string {
    return is_string($value) && trim($value) !== '' ? $value : $fallback;
};
$roleLabels = ['student' => 'Học viên', 'teacher' => 'Giảng viên', 'admin' => 'Quản trị viên'];
$pageTitle = 'Thông tin tài khoản';
$activeNav = 'admin';
require FE_DIR . '/partials/layout_head.php';
?>
<style>
.account-view { max-width:880px; margin:28px auto; padding:0 16px; }
.account-view-header { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:12px; margin-bottom:20px; }
.account-view-header p { margin-top:6px; color:var(--cl-muted); }
.account-view-actions { display:flex; flex-wrap:wrap; align-items:center; gap:10px; }
.account-view-card { min-width:0; margin-bottom:20px; padding:24px; background:var(--cl-card, #fff); border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.05); }
.account-view-card h3 { margin-bottom:16px; }
.account-view-fields { display:grid; grid-template-columns:minmax(120px, 1fr) minmax(0, 3fr); gap:12px 20px; margin:0; }
.account-view-fields dt { font-weight:600; color:var(--cl-muted); }
.account-view-fields dd { margin:0; min-width:0; overflow-wrap:anywhere; }
.account-view-note { margin:16px 0 0; color:var(--cl-muted); overflow-wrap:anywhere; }
@media (max-width:520px) {
    .account-view-card { padding:20px 16px; }
    .account-view-fields { grid-template-columns:minmax(0, 1fr); gap:6px; }
    .account-view-fields dd:not(:last-child) { margin-bottom:10px; }
}
</style>
<div class="account-view">
    <div class="account-view-header">
        <div>
            <h2>Thông tin tài khoản<?= $target ? ' #' . (int)$target['id'] : '' ?></h2>
            <p>Chỉ xem thông tin, không thay đổi tài khoản hoặc dữ liệu liên quan.</p>
        </div>
        <div class="account-view-actions">
            <a class="btn btn-secondary btn-back" href="<?= e($listUrl) ?>">Danh sách tài khoản</a>
            <?php if ($canManage): ?>
                <a class="aj-icon-action aj-icon-action--edit" href="<?= e(url('/frontend/admin/user_edit.php?' . http_build_query(['id' => (int)$target['id']] + $returnQuery))) ?>" aria-label="Sửa" data-action-label="Sửa" title="Sửa"><?= aj_action_icon('edit') ?></a>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($err !== ''): ?>
        <div role="alert" style="background:#fee2e2; color:#b91c1c; padding:12px 18px; border-radius:8px; margin-bottom:20px; font-weight:600;"><?= e($err) ?></div>
    <?php endif; ?>
    <?php if ($target): ?>
        <section class="account-view-card" aria-labelledby="account-identity-heading">
            <h3 id="account-identity-heading">Thông tin cơ bản</h3>
            <dl class="account-view-fields">
                <dt>Mã tài khoản</dt><dd>#<?= (int)$target['id'] ?></dd>
                <dt>Tên đăng nhập</dt><dd><?= e($displayText($target['username'])) ?></dd>
                <dt>Họ tên</dt><dd><?= e($displayText($target['full_name'])) ?></dd>
                <dt>Email</dt><dd><?= e($displayText($target['email'])) ?></dd>
                <dt>Vai trò</dt><dd><?= e($roleLabels[$target['role']] ?? 'Không xác định') ?><?= AdminPolicy::isPrimary($target) ? ' (Admin chính)' : '' ?></dd>
                <dt>Trạng thái</dt><dd><span class="badge <?= (int)$target['is_active'] === 1 ? 'verdict-AC' : 'verdict-CE' ?>"><?= (int)$target['is_active'] === 1 ? 'Hoạt động' : 'Bị khóa' ?></span></dd>
                <dt>Ngày tạo</dt><dd><?= e($displayText($target['created_at'])) ?></dd>
            </dl>
            <?php if (!$canManage): ?>
                <p class="account-view-note"><?= (int)$target['id'] === (int)$user['id']
                    ? 'Đây là tài khoản đang đăng nhập; các thao tác quản trị thay đổi chính tài khoản này được bảo vệ.'
                    : ((int)$target['id'] === AdminPolicy::primaryId() ? 'Admin chính được bảo vệ.' : 'Chỉ admin chính được quản lý tài khoản quản trị viên này.') ?></p>
            <?php endif; ?>
        </section>
        <?php if ($target['role'] === 'teacher'): ?>
        <section class="account-view-card" aria-labelledby="account-teaching-heading">
            <h3 id="account-teaching-heading">Giảng dạy</h3>
            <dl class="account-view-fields">
                <dt>Số lớp chủ quản</dt><dd><?= (int)$summary['owned_class_count'] ?></dd>
                <dt>Lớp đang chủ quản</dt><dd><?= e($displayText($summary['owned_class_names'], 'Không có lớp chủ quản.')) ?></dd>
                <dt>Số học viên đang quản lý</dt><dd><?= (int)$summary['student_count'] ?></dd>
            </dl>
        </section>
        <?php elseif ($target['role'] === 'student'): ?>
        <section class="account-view-card" aria-labelledby="account-learning-heading">
            <h3 id="account-learning-heading">Học tập</h3>
            <dl class="account-view-fields">
                <dt>Lớp đang tham gia</dt><dd><?= e($displayText($summary['class_names'], 'Chưa tham gia lớp nào.')) ?></dd>
                <dt>Tổng số bài nộp</dt><dd><?= (int)$summary['sub_count'] ?></dd>
                <dt>Số bài đã giải (AC)</dt><dd><?= (int)$summary['solved_count'] ?></dd>
                <dt>Điểm kinh nghiệm (XP)</dt><dd><?= (int)$summary['xp_points'] ?></dd>
            </dl>
        </section>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php require FE_DIR . '/partials/layout_foot.php'; ?>
