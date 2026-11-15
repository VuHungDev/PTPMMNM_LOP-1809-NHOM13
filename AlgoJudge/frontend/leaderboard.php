<?php
// frontend/leaderboard.php — Bảng xếp hạng XP học viên (phân trang + lọc lớp)
require_once __DIR__ . '/../backend/lib/LeaderboardScope.php';
$user = Auth::requireLogin();

try {
    $leaderboard = LeaderboardScope::resolve($pdo, $user, $_GET);
} catch (DomainException $e) {
    http_response_code($e->getCode());
    die($e->getMessage());
}
$leaderboardScope = $leaderboard['scope'];
$teacherSystem = $user['role'] === 'teacher' && $leaderboardScope === 'system';
$fClass = $leaderboard['class_id'] > 0 ? (string)$leaderboard['class_id'] : '';
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$perPage = 15;
$whereSql = $leaderboard['where_sql'];
$params = $leaderboard['params'];

$stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM users u $whereSql");
$stmtCnt->execute($params);
$totalRows = (int)$stmtCnt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$classNamesSql = $leaderboard['class_names_sql'];
$identitySql = $user['role'] === 'teacher' ? 'u.id, u.full_name' : 'u.id, u.username, u.full_name';
$stmt = $pdo->prepare("
    SELECT $identitySql, u.xp_points,
           $classNamesSql AS class_names,
           (SELECT COUNT(DISTINCT s.problem_id) FROM submissions s WHERE s.user_id = u.id AND s.final_verdict = 'AC') AS solved,
           (SELECT COUNT(*) FROM submissions s WHERE s.user_id = u.id) AS sub_total
    FROM users u $whereSql
    ORDER BY u.xp_points DESC, solved DESC, u.id ASC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

if ($user['role'] === 'admin' || $teacherSystem) {
    $classes = $pdo->query('SELECT id, name FROM classes ORDER BY name, id')->fetchAll();
} else {
    $stmtClasses = $pdo->prepare($user['role'] === 'teacher'
        ? 'SELECT id, name FROM classes WHERE owner_teacher_id = ? ORDER BY name, id'
        : "SELECT c.id, c.name FROM classes c JOIN class_members cm ON cm.class_id = c.id WHERE cm.user_id = ? AND cm.status = 'active' ORDER BY c.name, c.id");
    $stmtClasses->execute([(int)$user['id']]);
    $classes = $stmtClasses->fetchAll();
}
$baseQuery = array_filter(['class_id' => $fClass ?: null]);
if ($user['role'] === 'teacher') $baseQuery['scope'] = $leaderboardScope;
$exportQuery = http_build_query(array_merge(['type' => 'leaderboard'], $baseQuery));
$resetQuery = $user['role'] === 'teacher' ? '?' . http_build_query(['scope' => $leaderboardScope]) : '';

// Hạng của chính người xem (toàn bảng, không lọc)
$myRank = null;
if ($user['role'] === 'student') {
    $stmtR = $pdo->prepare("SELECT COUNT(*) + 1 FROM users WHERE role = 'student' AND xp_points > (SELECT xp_points FROM users WHERE id = ?)");
    $stmtR->execute([$user['id']]);
    $myRank = (int)$stmtR->fetchColumn();
}

$pageTitle = 'Bảng xếp hạng';
$activeNav = 'rank';
require FE_DIR . '/partials/layout_head.php';
?>
<div style="max-width:900px; margin:28px auto; padding:0 24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <div>
            <h2>🏆 Bảng xếp hạng XP</h2>
            <p style="color:var(--cl-muted);">Giải đúng 1 bài lần đầu: Easy +10 · Medium +20 · Hard +30 XP.
            <?php if ($myRank): ?>Hạng của bạn: <strong>#<?= $myRank ?></strong><?php endif; ?></p>
        </div>
        <?php if (Auth::isTeacher($user)): ?>
        <a class="btn btn-success" href="<?= url('/backend/api/export_excel.php?' . $exportQuery) ?>">📥 Xuất Excel</a>
        <?php endif; ?>
    </div>

    <?php if ($user['role'] === 'teacher'): ?>
    <form method="GET" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:12px;">
        <label for="leaderboard-scope">Phạm vi xếp hạng</label>
        <select id="leaderboard-scope" name="scope" class="form-control" style="max-width:260px;">
            <option value="managed" <?= !$teacherSystem ? 'selected' : '' ?>>Lớp tôi quản lý</option>
            <option value="system" <?= $teacherSystem ? 'selected' : '' ?>>Toàn hệ thống</option>
        </select>
        <button type="submit" class="aj-icon-action aj-icon-action--view" aria-label="Xem" data-action-label="Xem" title="Xem"><?= aj_action_icon('view') ?></button>
    </form>
    <p style="color:var(--cl-muted); margin-bottom:12px;">
        <?= $teacherSystem ? 'Đang xem toàn hệ thống: chỉ hiển thị họ tên, lớp và số liệu xếp hạng; không cấp quyền xem mã nguồn hay chi tiết bài nộp ngoài lớp quản lý.' : 'Chỉ hiển thị học viên đang thuộc các lớp bạn quản lý.' ?>
    </p>
    <?php endif; ?>

    <form method="GET" style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px; background:#fff; padding:14px; border-radius:10px;">
        <?php if ($user['role'] === 'teacher'): ?><input type="hidden" name="scope" value="<?= e($leaderboardScope) ?>"><?php endif; ?>
        <select name="class_id" aria-label="Lọc theo lớp" class="form-control" style="max-width:260px;">
            <option value="">-- <?= $user['role'] === 'teacher' && !$teacherSystem ? 'Mọi lớp của tôi' : 'Toàn hệ thống' ?> --</option>
            <?php foreach ($classes as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $fClass === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Lọc</button>
        <a href="<?= e(url('/frontend/leaderboard.php' . $resetQuery)) ?>" class="btn" style="background:#e2e8f0; color:#334155;">Đặt lại</a>
    </form>

    <div style="background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.05); overflow:hidden;">
        <table style="width:100%; border-collapse:collapse; font-size:0.92rem;">
            <thead><tr style="background:#f8fafc; text-align:left;">
                <th style="padding:12px 16px;">Hạng</th><th style="padding:12px 16px;">Học viên</th>
                <th style="padding:12px 16px;">Lớp</th><th style="padding:12px 16px;">XP</th>
                <th style="padding:12px 16px;">Bài đã giải</th><th style="padding:12px 16px;">Lượt nộp</th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $i => $r):
                    $rank = $offset + $i + 1;
                    $medal = $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : ($rank === 3 ? '🥉' : "#$rank"));
                    $isMe = $user['role'] === 'student' && (int)$r['id'] === (int)$user['id'];
                ?>
                <tr style="border-bottom:1px solid var(--cl-border); <?= $isMe ? 'background:#eff6ff;' : '' ?>">
                    <td style="padding:12px 16px; font-weight:800; font-size:1.05rem;"><?= $medal ?></td>
                    <td style="padding:12px 16px;">
                        <?php if ($user['role'] === 'teacher'): ?>
                            <strong><?= e($r['full_name'] ?: 'Chưa cập nhật họ tên') ?></strong>
                        <?php else: ?>
                            <strong><?= e($r['username']) ?></strong><br><small style="color:var(--cl-muted);"><?= e($r['full_name']) ?></small><?= $isMe ? ' <span class="badge verdict-AC">Bạn</span>' : '' ?>
                        <?php endif; ?>
                    </td>
                    <td style="padding:12px 16px;"><span class="badge" style="background:#f1f5f9;"><?= e($r['class_names'] ?: '—') ?></span></td>
                    <td style="padding:12px 16px; font-weight:800; color:var(--cl-primary);"><?= (int)$r['xp_points'] ?></td>
                    <td style="padding:12px 16px;"><?= (int)$r['solved'] ?></td>
                    <td style="padding:12px 16px;"><?= (int)$r['sub_total'] ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                <tr><td colspan="6" style="padding:24px; text-align:center; color:var(--cl-muted);">Chưa có học viên nào.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php require FE_DIR . '/partials/pagination.php'; ?>
    </div>
</div>
<?php require FE_DIR . '/partials/layout_foot.php'; ?>
