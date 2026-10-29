<?php
// frontend/leaderboard.php — Bảng xếp hạng XP học viên (phân trang + lọc lớp)
require_once __DIR__ . '/../backend/lib/Auth.php';
$user = Auth::requireLogin();

$fClass = trim($_GET['class_code'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$where = ["u.role = 'student'"];
$params = [];
if ($fClass !== '') {
    $where[] = 'u.class_code = ?';
    $params[] = $fClass;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM users u $whereSql");
$stmtCnt->execute($params);
$totalRows = (int)$stmtCnt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT u.id, u.username, u.full_name, u.class_code, u.xp_points,
           (SELECT COUNT(DISTINCT s.problem_id) FROM submissions s WHERE s.user_id = u.id AND s.final_verdict = 'AC') AS solved,
           (SELECT COUNT(*) FROM submissions s WHERE s.user_id = u.id) AS sub_total
    FROM users u $whereSql
    ORDER BY u.xp_points DESC, solved DESC, u.id ASC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$classes = $pdo->query("SELECT DISTINCT class_code FROM users WHERE role = 'student' ORDER BY class_code ASC")->fetchAll();
$baseQuery = array_filter(['class_code' => $fClass ?: null]);

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
    </div>

    <form method="GET" style="display:flex; gap:10px; margin-bottom:16px; background:#fff; padding:14px; border-radius:10px;">
        <select name="class_code" class="form-control" style="max-width:200px;">
            <option value="">-- Mọi lớp --</option>
            <?php foreach ($classes as $c): ?>
                <option value="<?= e($c['class_code']) ?>" <?= $fClass === $c['class_code'] ? 'selected' : '' ?>><?= e($c['class_code']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Lọc</button>
        <a href="<?= url('/frontend/leaderboard.php') ?>" class="btn" style="background:#e2e8f0; color:#334155;">Đặt lại</a>
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
                    <td style="padding:12px 16px;"><strong><?= e($r['username']) ?></strong><br><small style="color:var(--cl-muted);"><?= e($r['full_name']) ?></small><?= $isMe ? ' <span class="badge verdict-AC">Bạn</span>' : '' ?></td>
                    <td style="padding:12px 16px;"><span class="badge" style="background:#f1f5f9;"><?= e($r['class_code']) ?></span></td>
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
