<?php
require_once __DIR__ . '/../../backend/lib/Auth.php';
require_once __DIR__ . '/../../backend/lib/ClassManager.php';
$user = Auth::requireRole(['student', 'teacher', 'admin']);
$role = $user['role'];
$text = static function (array $input, string $key): string {
    return is_string($input[$key] ?? null) ? trim($input[$key]) : '';
};
$integer = static function (array $input, string $key, int $default = 0): int {
    $value = $input[$key] ?? null;
    return is_scalar($value) && filter_var($value, FILTER_VALIDATE_INT) !== false ? (int)$value : $default;
};
$page = max(1, $integer($_GET, 'page', 1));
$selectedClassId = max(0, $integer($_GET, 'class_id'));
$studentView = $role === 'student' && in_array($text($_GET, 'view'), ['join', 'requests'], true) ? $text($_GET, 'view') : '';
if ($role === 'student') {
    // Do not retain a private roster in browser caches after membership is removed.
    header('Cache-Control: private, no-store');
    if (array_key_exists('class_id', $_GET) && !$selectedClassId) {
        http_response_code(404);
        die('Lớp không tồn tại hoặc bạn không còn là thành viên của lớp.');
    }
    if ($selectedClassId) $studentView = '';
}
$listPage = $selectedClassId ? max(1, $integer($_GET, 'list_page', 1)) : $page;
$fSearch = $role === 'student' ? '' : mb_substr($text($_GET, 'q'), 0, 100);
$fOwner = $role === 'admin' ? $text($_GET, 'owner_id') : '';
if ($fOwner !== '' && (filter_var($fOwner, FILTER_VALIDATE_INT) === false || (int)$fOwner < 0)) $fOwner = '';
$selectedStudentId = $role === 'admin' ? max(0, $integer($_GET, 'student_id')) : 0;
$selectedStudentUsername = '';
if ($selectedStudentId) {
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ? AND role = 'student'");
    $stmt->execute([$selectedStudentId]);
    $selectedStudentUsername = (string)$stmt->fetchColumn();
    if ($selectedStudentUsername === '') $selectedStudentId = 0;
}
$context = [];
if ($fSearch !== '') $context['q'] = $fSearch;
if ($fOwner !== '') $context['owner_id'] = $fOwner;
if ($selectedStudentId) $context['student_id'] = $selectedStudentId;
$pageUrl = static function (array $query = []): string {
    return url('/frontend/instructor/classes.php') . ($query ? '?' . http_build_query($query) : '');
};
$viewQuery = array_merge($context, ['page' => $page]);
if ($selectedClassId) $viewQuery += ['class_id' => $selectedClassId, 'list_page' => $listPage];
if ($studentView !== '') $viewQuery['view'] = $studentView;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $text($_POST, 'action');
    $classId = max(0, $integer($_POST, 'class_id'));
    $msgOk = true;
    try {
        switch ($action) {
            case 'request_join':
                $msg = ClassManager::requestJoin($user, $text($_POST, 'join_code'));
                break;
            case 'create_class':
                $classId = ClassManager::createClass($user, $text($_POST, 'name'), $integer($_POST, 'owner_teacher_id'));
                $viewQuery = array_merge($context, ['class_id' => $classId, 'list_page' => $listPage]);
                $msg = 'Đã tạo lớp. Chia sẻ mã mời bên dưới để học viên gửi yêu cầu tham gia.';
                break;
            case 'assign_owner':
                ClassManager::assignOwner($user, $classId, $integer($_POST, 'owner_teacher_id'));
                $msg = 'Đã lưu giáo viên chủ quản. Mã mời được giữ nguyên nếu đã kích hoạt.';
                break;
            case 'activate_code':
            case 'rotate_code':
                ClassManager::changeJoinCode($user, $classId, $action === 'activate_code');
                $msg = $action === 'activate_code' ? 'Đã kích hoạt mã mời cho lớp.' : 'Đã đổi mã mời. Mã cũ không còn dùng để gửi yêu cầu mới; thành viên và yêu cầu đang chờ không bị thay đổi.';
                break;
            case 'add_student':
                $msg = ClassManager::addStudent($user, $classId, $text($_POST, 'identifier'));
                break;
            case 'approve_member':
            case 'reject_member':
            case 'remove_member':
                ClassManager::updateMember($user, $classId, $integer($_POST, 'student_id'), $action);
                $msg = ['approve_member' => 'Đã duyệt học viên.', 'reject_member' => 'Đã từ chối yêu cầu.', 'remove_member' => 'Đã đưa học viên ra khỏi lớp. Lịch sử thành viên và bài nộp được giữ lại.'][$action];
                break;
            default:
                throw new DomainException('Thao tác không hợp lệ.');
        }
    } catch (Throwable $e) {
        $msg = $e instanceof DomainException ? $e->getMessage() : 'Không thể hoàn tất thao tác. Hãy tải lại trang và thử lại.';
        $msgOk = false;
    }
    if ($role === 'student' && $action === 'request_join') {
        $viewQuery = ['view' => $msgOk ? 'requests' : 'join'];
    }
    $_SESSION['classes_flash'] = ['message' => $msg, 'ok' => $msgOk];
    header('Location: ' . $pageUrl($viewQuery), true, 303);
    exit;
}

$flash = $_SESSION['classes_flash'] ?? null;
unset($_SESSION['classes_flash']);
$perPage = 20;
$classes = $members = $myMemberships = [];
$selectedClass = null;
$params = [];
if ($role === 'student') {
    if ($selectedClassId) {
        $joinedClass = ClassManager::getJoinedClass($user, $selectedClassId, $page, $perPage);
        if (!$joinedClass) {
            http_response_code(404);
            die('Lớp không tồn tại hoặc bạn không còn là thành viên của lớp.');
        }
        $selectedClass = $joinedClass['class'];
        $members = $joinedClass['members'];
        $totalRows = $joinedClass['total_rows'];
        $page = $joinedClass['page'];
    } elseif ($studentView === 'join') {
        $totalRows = 0;
    } else {
        $membershipFilter = $studentView === 'requests' ? '' : " AND cm.status = 'active'";
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM class_members cm JOIN classes c ON c.id = cm.class_id WHERE cm.user_id = ?' . $membershipFilter);
        $stmt->execute([(int)$user['id']]);
        $totalRows = (int)$stmt->fetchColumn();
    }
} elseif ($selectedClassId) {
    $selectedClass = ClassManager::getManagedClass($user, $selectedClassId);
    if (!$selectedClass) {
        http_response_code($role === 'teacher' ? 403 : 404);
        die('Lớp không tồn tại hoặc bạn không quản lý lớp này.');
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM class_members WHERE class_id = ?');
    $stmt->execute([$selectedClassId]);
    $totalRows = (int)$stmt->fetchColumn();
} else {
    $where = [];
    if ($role === 'teacher') {
        $where[] = 'c.owner_teacher_id = ?';
        $params[] = (int)$user['id'];
    } elseif ($fOwner !== '') {
        if ((int)$fOwner === 0) {
            $where[] = 'c.owner_teacher_id IS NULL';
        } else {
            $where[] = 'c.owner_teacher_id = ?';
            $params[] = (int)$fOwner;
        }
    }
    if ($fSearch !== '') {
        $where[] = '(c.name LIKE ? OR c.legacy_class_code LIKE ?)';
        $params[] = '%' . $fSearch . '%';
        $params[] = '%' . $fSearch . '%';
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM classes c' . $whereSql);
    $stmt->execute($params);
    $totalRows = (int)$stmt->fetchColumn();
}
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
if ($role === 'student') {
    $baseQuery = $studentView !== '' ? ['view' => $studentView] : [];
    if ($selectedClass) {
        $baseQuery = ['class_id' => $selectedClassId, 'list_page' => $listPage];
    } elseif ($studentView !== 'join') {
        if ($studentView === 'requests') {
            // Current own request statuses only: membership rows are not immutable event history.
            $sql = 'SELECT c.id, c.name, cm.status, cm.requested_at, cm.updated_at FROM class_members cm JOIN classes c ON c.id = cm.class_id WHERE cm.user_id = ? ORDER BY cm.updated_at DESC, cm.id DESC';
        } else {
            $sql = "SELECT c.id, c.name FROM class_members cm JOIN classes c ON c.id = cm.class_id WHERE cm.user_id = ? AND cm.status = 'active' ORDER BY c.name, c.id";
        }
        $stmt = $pdo->prepare($sql . ' LIMIT ' . $perPage . ' OFFSET ' . $offset);
        $stmt->execute([(int)$user['id']]);
        $myMemberships = $stmt->fetchAll();
    }
} elseif ($selectedClass) {
    $stmt = $pdo->prepare("SELECT cm.status, cm.requested_at, cm.updated_at, u.id, u.username, u.full_name, u.email, u.role, u.is_active FROM class_members cm JOIN users u ON u.id = cm.user_id WHERE cm.class_id = ? ORDER BY FIELD(cm.status, 'pending', 'active', 'rejected', 'removed'), u.username, u.id LIMIT " . $perPage . ' OFFSET ' . $offset);
    $stmt->execute([$selectedClassId]);
    $members = $stmt->fetchAll();
    $baseQuery = array_merge($context, ['class_id' => $selectedClassId, 'list_page' => $listPage]);
} else {
    $listPage = $page;
    $stmt = $pdo->prepare('SELECT c.*, u.full_name AS owner_name, u.username AS owner_username FROM classes c LEFT JOIN users u ON u.id = c.owner_teacher_id' . $whereSql . ' ORDER BY c.name, c.id LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $stmt->execute($params);
    $classes = $stmt->fetchAll();
    $baseQuery = $context;
}
$formUrl = $pageUrl(array_merge($baseQuery, ['page' => $page]));
$teachers = $role === 'admin' ? $pdo->query("SELECT id, full_name, username, is_active FROM users WHERE role = 'teacher' ORDER BY full_name, id")->fetchAll() : [];
$statusLabels = ['pending' => 'Chờ duyệt', 'active' => 'Đã duyệt', 'rejected' => 'Đã từ chối', 'removed' => 'Đã rời lớp'];
$pageTitle = $role !== 'student' ? 'Quản lý lớp' : ($selectedClass ? 'Thành viên lớp học' : (['join' => 'Tham gia lớp học', 'requests' => 'Yêu cầu của tôi'][$studentView] ?? 'Lớp học của tôi'));
$activeNav = 'classes';
require FE_DIR . '/partials/layout_head.php';
?>
<style>
.classes-page { max-width:1200px; margin:28px auto; padding:0 24px; overflow-wrap:anywhere; }
.classes-page .btn { min-height:44px; min-width:44px; max-width:100%; white-space:normal; }
.classes-page form, .classes-page form > *, .classes-page select { min-width:0; max-width:100%; }
.classes-page .class-card { background:var(--cl-card); padding:22px; border:1px solid var(--cl-border); border-radius:12px; }
.classes-page .class-card p { margin:10px 0 16px; }
.classes-page .class-back, .classes-page .class-actions { margin-bottom:18px; }
.classes-page .class-actions { display:flex; flex-wrap:wrap; gap:12px; }
.classes-page .class-muted { color:var(--cl-muted); }
.classes-page .class-join-form { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; max-width:580px; margin:16px 0; }
.classes-page .class-join-input { flex:1 1 240px; }
.classes-page .class-join-input label { display:block; font-weight:600; margin-bottom:6px; }
.classes-page .class-joined-list { list-style:none; display:grid; gap:12px; margin-top:16px; }
.classes-page .class-joined-list .btn { width:100%; justify-content:space-between; text-align:left; }
.classes-page .class-table { table-layout:fixed; }
.classes-page .class-table th, .classes-page .class-table td { overflow-wrap:anywhere; }
.classes-page .class-student-table { width:100%; border-collapse:collapse; }
.classes-page .class-student-table th { text-align:left; background:var(--cl-bg); }
.classes-page .class-student-table th, .classes-page .class-student-table td { padding:12px; border-bottom:1px solid var(--cl-border); }
.classes-page .class-member-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
@media (max-width:600px) {
    .classes-page { padding:0 16px; }
    .classes-page .class-card { padding:16px; }
    .classes-page .class-actions .btn { flex:1 1 180px; justify-content:center; }
    .classes-page .class-join-form > button { width:100%; justify-content:center; }
    .classes-page .class-table thead { position:absolute; width:1px; height:1px; overflow:hidden; clip-path:inset(50%); }
    .classes-page .class-table, .classes-page .class-table tbody, .classes-page .class-table tr, .classes-page .class-table td { display:block; width:100%; }
    .classes-page .class-table tr { padding:10px 0; }
    .classes-page .class-table td[data-label]::before { content:attr(data-label); display:block; font-weight:600; font-size:0.8rem; color:var(--cl-muted); margin-bottom:4px; }
}
</style>
<div class="classes-page">
    <h2 style="margin-bottom:16px;">Lớp học</h2>
    <?php if ($flash): ?><div role="status" style="background:<?= $flash['ok'] ? '#dcfce7; color:#15803d' : '#fee2e2; color:#b91c1c' ?>; padding:12px 18px; border-radius:8px; margin-bottom:16px;"><?= e($flash['message']) ?></div><?php endif; ?>

    <?php if ($role === 'student'): ?>
        <?php if ($selectedClass || $studentView !== ''): ?>
            <p class="class-back"><a class="btn btn-back" href="<?= e($pageUrl($selectedClass ? ['page' => $listPage] : [])) ?>">← Lớp học của tôi</a></p>
        <?php endif; ?>
        <?php if ($selectedClass): ?>
            <section class="class-card" aria-labelledby="joined-class-title">
                <h3 id="joined-class-title"><?= e($selectedClass['name']) ?></h3>
                <p>Giáo viên chủ quản:
                    <?php if ($selectedClass['owner_username'] !== null): ?>
                        <strong><?= e($selectedClass['owner_name'] ?: $selectedClass['owner_username']) ?></strong>
                        <span class="class-muted">(@<?= e($selectedClass['owner_username']) ?>)</span>
                    <?php else: ?>
                        <span class="class-muted">Chưa có giáo viên chủ quản đang hoạt động.</span>
                    <?php endif; ?>
                </p>
                <h4>Học viên đang tham gia (<?= $totalRows ?>)</h4>
                <p class="class-muted">Danh sách chỉ đọc, chỉ gồm học viên đang là thành viên của lớp.</p>
                <table class="class-table class-student-table">
                    <thead><tr><th scope="col">Họ và tên</th><th scope="col">Tên đăng nhập</th></tr></thead>
                    <tbody>
                    <?php foreach ($members as $member): ?>
                        <tr><td data-label="Họ và tên"><?= e($member['full_name'] ?: $member['username']) ?></td><td data-label="Tên đăng nhập"><?= e($member['username']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$members): ?><tr><td colspan="2">Chưa có học viên đang tham gia.</td></tr><?php endif; ?>
                    </tbody>
                </table>
                <?php require FE_DIR . '/partials/pagination.php'; ?>
            </section>
        <?php elseif ($studentView === 'join'): ?>
            <section class="class-card" aria-labelledby="join-class-title">
                <h3 id="join-class-title">Tham gia lớp học</h3>
                <p>Nhập mã do giáo viên cung cấp. Yêu cầu chờ duyệt chưa cấp quyền thành viên. Bạn có thể tham gia nhiều lớp.</p>
                <form method="POST" action="<?= e($pageUrl(['view' => 'join'])) ?>" class="class-join-form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="request_join">
                    <div class="class-join-input"><label for="join-code">Mã tham gia</label><input id="join-code" class="form-control" name="join_code" minlength="16" maxlength="16" pattern="[A-Fa-f0-9]{16}" required placeholder="16 ký tự do giáo viên cung cấp" autocomplete="off"></div>
                    <button type="submit" class="btn btn-primary">Gửi yêu cầu</button>
                </form>
                <a class="btn btn-secondary" href="<?= e($pageUrl(['view' => 'requests'])) ?>">Yêu cầu của tôi</a>
            </section>
        <?php elseif ($studentView === 'requests'): ?>
            <section class="class-card" aria-labelledby="class-requests-title">
                <h3 id="class-requests-title">Yêu cầu của tôi (<?= $totalRows ?>)</h3>
                <p class="class-muted">Trạng thái hiện tại của yêu cầu và tư cách thành viên của bạn. Khi gửi lại yêu cầu, trạng thái cũ sẽ được cập nhật; đây không phải lịch sử đầy đủ của mọi lần thay đổi.</p>
                <table class="class-table class-student-table">
                    <thead><tr><th scope="col">Lớp học</th><th scope="col">Trạng thái</th><th scope="col">Cập nhật gần nhất</th></tr></thead>
                    <tbody>
                    <?php foreach ($myMemberships as $membership): ?>
                        <tr>
                            <td data-label="Lớp học"><?= e($membership['name']) ?></td>
                            <td data-label="Trạng thái"><?= e($statusLabels[$membership['status']] ?? $membership['status']) ?></td>
                            <td data-label="Cập nhật gần nhất"><?= e($membership['updated_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$myMemberships): ?><tr><td colspan="3">Bạn chưa có yêu cầu tham gia hoặc tư cách thành viên nào được ghi nhận.</td></tr><?php endif; ?>
                    </tbody>
                </table>
                <?php require FE_DIR . '/partials/pagination.php'; ?>
                <p class="class-muted">Nếu yêu cầu đã bị từ chối hoặc bạn đã rời lớp, nhập lại mã mời hiện tại để gửi yêu cầu mới.</p>
                <a class="btn btn-secondary" href="<?= e($pageUrl(['view' => 'join'])) ?>">Tham gia lớp học</a>
            </section>
        <?php else: ?>
            <nav class="class-actions" aria-label="Thao tác lớp học">
                <a class="btn btn-secondary" href="<?= e($pageUrl(['view' => 'join'])) ?>">Tham gia lớp học</a>
                <a class="btn btn-secondary" href="<?= e($pageUrl(['view' => 'requests'])) ?>">Yêu cầu của tôi</a>
            </nav>
            <section class="class-card" aria-labelledby="my-classes-title">
                <h3 id="my-classes-title">Lớp học đang tham gia (<?= $totalRows ?>)</h3>
                <p class="class-muted">Chọn lớp để xem giáo viên chủ quản và danh sách học viên.</p>
                <?php if (!$myMemberships): ?><p>Bạn chưa tham gia lớp học nào. Gửi yêu cầu bằng mã giáo viên cung cấp và chờ được duyệt.</p><?php endif; ?>
                <ul class="class-joined-list">
                <?php foreach ($myMemberships as $membership): ?>
                    <li><a class="btn btn-secondary" href="<?= e($pageUrl(['class_id' => (int)$membership['id'], 'list_page' => $page])) ?>"><?= e($membership['name']) ?></a></li>
                <?php endforeach; ?>
                </ul>
                <?php require FE_DIR . '/partials/pagination.php'; ?>
            </section>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($selectedStudentId): ?>
            <p>Đang gán lớp cho học viên <strong><?= e($selectedStudentUsername) ?></strong>. Chọn lớp rồi bấm “Thêm học viên”. <a href="<?= e($pageUrl(array_diff_key(array_merge($baseQuery, ['page' => $page]), ['student_id' => true]))) ?>">Bỏ chọn học viên</a></p>
        <?php endif; ?>
        <?php if (!$selectedClass): ?>
            <div style="background:#fff; padding:20px; border-radius:12px; margin-bottom:18px;">
                <h3>Tạo lớp mới</h3>
                <form method="POST" action="<?= e($formUrl) ?>" style="display:flex; gap:8px; flex-wrap:wrap;">
                    <?= csrf_field() ?><input type="hidden" name="action" value="create_class">
                    <input class="form-control" name="name" required maxlength="100" placeholder="Tên lớp" aria-label="Tên lớp" style="max-width:360px;">
                    <?php if ($role === 'admin'): ?>
                        <select name="owner_teacher_id" class="form-control" required aria-label="Giáo viên chủ quản" style="max-width:300px;">
                            <option value="">Chọn giáo viên chủ quản</option>
                            <?php foreach ($teachers as $teacher): if (!(int)$teacher['is_active']) continue; ?>
                                <option value="<?= (int)$teacher['id'] ?>"><?= e($teacher['full_name']) ?> (<?= e($teacher['username']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary">Tạo lớp</button>
                </form>
                <?php if ($role === 'admin'): ?><p style="color:var(--cl-muted);">Mỗi lớp có một giáo viên chủ quản. Chọn một lớp bên dưới để gán hoặc đổi chủ quản.</p><?php endif; ?>
            </div>
            <div style="background:#fff; padding:20px; border-radius:12px;">
                <h3>Danh sách lớp (<?= $totalRows ?>)</h3>
                <form method="GET" action="<?= e($pageUrl()) ?>" style="display:flex; gap:8px; flex-wrap:wrap; margin:12px 0;">
                    <?php if ($selectedStudentId): ?><input type="hidden" name="student_id" value="<?= $selectedStudentId ?>"><?php endif; ?>
                    <input class="form-control" name="q" value="<?= e($fSearch) ?>" maxlength="100" placeholder="Tên lớp hoặc mã lớp cũ" aria-label="Tìm lớp" style="max-width:300px;">
                    <?php if ($role === 'admin'): ?>
                        <select name="owner_id" class="form-control" aria-label="Lọc giáo viên chủ quản" style="max-width:300px;">
                            <option value="">Mọi giáo viên chủ quản</option><option value="0" <?= $fOwner === '0' ? 'selected' : '' ?>>Chưa gán chủ quản</option>
                            <?php foreach ($teachers as $teacher): ?><option value="<?= (int)$teacher['id'] ?>" <?= $fOwner === (string)$teacher['id'] ? 'selected' : '' ?>><?= e($teacher['full_name']) ?> (<?= e($teacher['username']) ?>)<?= !(int)$teacher['is_active'] ? ' — bị khóa' : '' ?></option><?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <button class="btn btn-primary" type="submit">Lọc</button>
                    <a class="btn" href="<?= e($pageUrl($selectedStudentId ? ['student_id' => $selectedStudentId] : [])) ?>">Đặt lại</a>
                </form>
                <div style="overflow-x:auto;">
                    <table class="class-table" style="width:100%; border-collapse:collapse;">
                        <thead><tr style="text-align:left; background:#f8fafc;"><th style="padding:10px;">Lớp</th><th style="padding:10px;">Giáo viên chủ quản</th><th style="padding:10px;">Mã mời</th><th style="padding:10px;">Thao tác</th></tr></thead>
                        <tbody>
                        <?php foreach ($classes as $class): ?>
                            <tr style="border-bottom:1px solid var(--cl-border);">
                                <td data-label="Lớp" style="padding:10px;"><strong><?= e($class['name']) ?></strong><?php if ($class['legacy_class_code']): ?><small style="display:block;">Mã cũ: <?= e($class['legacy_class_code']) ?></small><?php endif; ?></td>
                                <td data-label="Giáo viên chủ quản" style="padding:10px;"><?= e($class['owner_name'] ?: 'Chưa gán') ?><?php if ($class['owner_username']): ?><small style="display:block;"><?= e($class['owner_username']) ?></small><?php endif; ?></td>
                                <td data-label="Mã mời" style="padding:10px;"><?= $class['join_code'] ? 'Đã kích hoạt' : 'Chưa kích hoạt' ?></td>
                                <td data-label="Thao tác" style="padding:10px;"><a class="btn btn-primary" href="<?= e($pageUrl(array_merge($context, ['class_id' => (int)$class['id'], 'list_page' => $page]))) ?>">Quản lý lớp</a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$classes): ?><tr><td colspan="4" style="padding:18px; text-align:center;">Không có lớp phù hợp. Thử đổi bộ lọc hoặc tạo lớp mới.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php require FE_DIR . '/partials/pagination.php'; ?>
            </div>
        <?php else: ?>
            <p class="class-back"><a class="btn btn-back" href="<?= e($pageUrl(array_merge($context, ['page' => $listPage]))) ?>">← Danh sách lớp</a></p>
            <?php if ($role === 'admin'): ?>
                <div style="background:#fff; padding:20px; border-radius:12px; margin-bottom:18px;">
                    <h3>Giáo viên chủ quản</h3>
                    <p>Chủ quản hiện tại: <?= e($selectedClass['owner_name'] ?: 'Chưa gán') ?>. Đổi chủ quản sẽ chuyển quyền quản lý lớp, không xóa thành viên.</p>
                    <form method="POST" action="<?= e($formUrl) ?>" style="display:flex; gap:8px; flex-wrap:wrap;">
                        <?= csrf_field() ?><input type="hidden" name="action" value="assign_owner"><input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
                        <select name="owner_teacher_id" class="form-control" required aria-label="Giáo viên chủ quản mới" style="max-width:360px;">
                            <option value="">Chọn giáo viên</option>
                            <?php foreach ($teachers as $teacher): if (!(int)$teacher['is_active']) continue; ?>
                                <option value="<?= (int)$teacher['id'] ?>" <?= (int)$selectedClass['owner_teacher_id'] === (int)$teacher['id'] ? 'selected' : '' ?>><?= e($teacher['full_name']) ?> (<?= e($teacher['username']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-primary" type="submit">Lưu chủ quản</button>
                    </form>
                </div>
            <?php endif; ?>
            <div style="background:#fff; padding:20px; border-radius:12px;">
                <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:center;">
                    <div><h3><?= e($selectedClass['name']) ?></h3><p>Mã mời: <code style="font-size:1.05rem; user-select:all;"><?= e($selectedClass['join_code'] ?: 'Chưa kích hoạt') ?></code></p></div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                        <a class="btn btn-success" href="<?= e(url('/backend/api/export_excel.php?' . http_build_query(['type' => 'roster', 'class_id' => $selectedClassId]))) ?>">Xuất Excel toàn bộ lớp</a>
                        <?php if ($selectedClass['owner_role'] === 'teacher' && (int)$selectedClass['owner_active'] === 1): ?>
                            <form method="POST" action="<?= e($formUrl) ?>" <?= $selectedClass['join_code'] ? 'onsubmit="return confirm(\'Đổi mã mời? Mã cũ sẽ không nhận yêu cầu mới. Các thành viên và yêu cầu hiện tại được giữ nguyên.\');"' : '' ?>>
                                <?= csrf_field() ?><input type="hidden" name="action" value="<?= $selectedClass['join_code'] ? 'rotate_code' : 'activate_code' ?>"><input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
                                <button class="btn" type="submit"><?= $selectedClass['join_code'] ? 'Đổi mã mời' : 'Kích hoạt mã mời' ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($selectedClass['owner_role'] !== 'teacher' || (int)$selectedClass['owner_active'] !== 1): ?><p style="color:#b91c1c;">Lớp chưa có giáo viên chủ quản đang hoạt động nên chưa thể nhận yêu cầu qua mã mời. Quản trị viên cần gán lại chủ quản.</p><?php endif; ?>
                <p style="color:var(--cl-muted);">Chỉ thêm tài khoản học viên đã đăng ký. Thêm trực tiếp sẽ duyệt ngay; trang này không tạo tài khoản hoặc sửa hồ sơ học viên.</p>
                <form method="POST" action="<?= e($formUrl) ?>" style="display:flex; gap:8px; margin:14px 0; max-width:650px; flex-wrap:wrap;">
                    <?= csrf_field() ?><input type="hidden" name="action" value="add_student"><input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
                    <input class="form-control" name="identifier" required maxlength="100" value="<?= e($selectedStudentUsername) ?>" placeholder="Username hoặc email học viên đã đăng ký" aria-label="Username hoặc email học viên" style="flex:1 1 220px; min-width:0;">
                    <button class="btn btn-primary" type="submit">Thêm học viên</button>
                </form>
                <h4>Thành viên và yêu cầu (<?= $totalRows ?>)</h4>
                <p style="color:var(--cl-muted);">Yêu cầu chờ duyệt được xếp trước. Đưa học viên ra khỏi lớp chỉ thu hồi tư cách thành viên lớp này, không xóa tài khoản hoặc bài nộp.</p>
                <div style="overflow-x:auto;">
                    <table class="class-table" style="width:100%; border-collapse:collapse;">
                        <thead><tr style="text-align:left; background:#f8fafc;"><th style="padding:9px;">Học viên</th><th style="padding:9px;">Email</th><th style="padding:9px;">Trạng thái / cập nhật</th><th style="padding:9px;">Thao tác</th></tr></thead>
                        <tbody>
                        <?php foreach ($members as $member): ?>
                            <tr style="border-bottom:1px solid var(--cl-border);">
                                <td data-label="Học viên" style="padding:9px;"><?= e($member['full_name']) ?> (<code><?= e($member['username']) ?></code>)<?php if ($member['role'] !== 'student'): ?><small style="display:block;">Đã chuyển vai trò: <?= e($member['role']) ?></small><?php endif; ?><?php if (!(int)$member['is_active']): ?><small style="display:block;">Tài khoản bị khóa</small><?php endif; ?></td>
                                <td data-label="Email" style="padding:9px;"><?= e($member['email']) ?></td>
                                <td data-label="Trạng thái / cập nhật" style="padding:9px;"><?= e($statusLabels[$member['status']] ?? $member['status']) ?><small style="display:block; color:var(--cl-muted);"><?= e($member['updated_at']) ?></small></td>
                                <td data-label="Thao tác" style="padding:9px;"><div class="class-member-actions">
                                    <?php if ($member['status'] === 'pending'): ?>
                                        <?php foreach (['approve_member' => 'Duyệt', 'reject_member' => 'Từ chối'] as $action => $label): if ($action === 'approve_member' && ($member['role'] !== 'student' || !(int)$member['is_active'])) continue; ?>
                                            <form method="POST" action="<?= e($formUrl) ?>" style="display:inline;">
                                                <?= csrf_field() ?><input type="hidden" name="action" value="<?= $action ?>"><input type="hidden" name="class_id" value="<?= $selectedClassId ?>"><input type="hidden" name="student_id" value="<?= (int)$member['id'] ?>">
                                                <button type="submit" class="btn <?= $action === 'approve_member' ? 'btn-primary' : '' ?>" style="padding:4px 8px;"><?= $label ?></button>
                                            </form>
                                        <?php endforeach; ?>
                                    <?php elseif ($member['status'] === 'active'): ?>
                                        <form method="POST" action="<?= e($formUrl) ?>" style="display:inline;" onsubmit="return confirm('Đưa học viên này ra khỏi lớp? Tài khoản và lịch sử bài nộp vẫn được giữ lại.');">
                                            <?= csrf_field() ?><input type="hidden" name="action" value="remove_member"><input type="hidden" name="class_id" value="<?= $selectedClassId ?>"><input type="hidden" name="student_id" value="<?= (int)$member['id'] ?>">
                                            <button type="submit" class="btn" style="padding:4px 8px; color:#b91c1c;">Đưa ra khỏi lớp</button>
                                        </form>
                                    <?php else: ?><span style="color:var(--cl-muted);">Đã lưu lịch sử</span><?php endif; ?>
                                </div></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$members): ?><tr><td colspan="4" style="padding:18px; text-align:center;">Chưa có học viên hoặc yêu cầu tham gia.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php require FE_DIR . '/partials/pagination.php'; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php require FE_DIR . '/partials/layout_foot.php'; ?>
