<?php
// frontend/instructor/progress.php — Báo cáo tiến độ học viên: lọc + phân trang + xuất Excel
require_once __DIR__ . '/../../backend/lib/Auth.php';
$user = Auth::requireRole(['teacher', 'admin']);

$fClass = filter_var($_GET['class_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$fClass = $fClass === false ? '' : (string)$fClass;
$fSearch = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
if ($user['role'] === 'teacher' && $fClass !== '' && !Auth::teacherOwnsClass((int)$user['id'], (int)$fClass)) {
    http_response_code(403);
    die('403 Forbidden — Bạn không quản lý lớp này.');
}

$where = ["u.role = 'student'"];
$params = [];
if ($user['role'] === 'teacher') {
    if ($fClass !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM class_members cm JOIN classes c ON c.id = cm.class_id WHERE cm.user_id = u.id AND cm.class_id = ? AND cm.status = \'active\' AND c.owner_teacher_id = ?)';
        $params[] = (int)$fClass;
        $params[] = (int)$user['id'];
    } else {
        $where[] = Auth::teacherStudentScope('u');
        $params[] = (int)$user['id'];
    }
} elseif ($fClass !== '') {
    $where[] = "EXISTS (SELECT 1 FROM class_members cm WHERE cm.user_id = u.id AND cm.class_id = ? AND cm.status = 'active')";
    $params[] = (int)$fClass;
}
if ($fSearch !== '') {
    $where[] = '(u.username LIKE ? OR u.full_name LIKE ?)';
    $params[] = "%$fSearch%";
    $params[] = "%$fSearch%";
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM users u $whereSql");
$stmtCnt->execute($params);
$totalRows = (int)$stmtCnt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$classNamesSql = Auth::classNamesSql($user);
$stmt = $pdo->prepare("
    SELECT u.id, u.username, u.full_name,
           $classNamesSql AS class_names,
           (SELECT COUNT(*) FROM submissions s WHERE s.user_id = u.id) AS sub_total,
           (SELECT COUNT(DISTINCT s.problem_id) FROM submissions s JOIN problems p ON p.id = s.problem_id WHERE s.user_id = u.id AND s.final_verdict = 'AC' AND p.is_active = 1) AS solved,
           (SELECT MAX(s.created_at) FROM submissions s WHERE s.user_id = u.id) AS last_submit
    FROM users u $whereSql
    ORDER BY solved DESC, sub_total DESC, u.id ASC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$totalProblems = (int)$pdo->query("SELECT COUNT(*) FROM problems WHERE is_active = 1")->fetchColumn();

$classes = $user['role'] === 'admin'
    ? $pdo->query('SELECT id, name FROM classes ORDER BY name ASC')->fetchAll()
    : (function () use ($pdo, $user) { $stmt = $pdo->prepare('SELECT id, name FROM classes WHERE owner_teacher_id = ? ORDER BY name ASC'); $stmt->execute([(int)$user['id']]); return $stmt->fetchAll(); })();

$stmtDist = $pdo->prepare("SELECT s.final_verdict AS v, COUNT(*) AS c FROM submissions s JOIN users u ON u.id = s.user_id $whereSql GROUP BY s.final_verdict");
$stmtDist->execute($params);
$distRows = $stmtDist->fetchAll(PDO::FETCH_KEY_PAIR);
$distTotal = array_sum($distRows);
$distColors = ['AC' => '#10b981', 'WA' => '#ef4444', 'TLE' => '#f59e0b', 'RE' => '#8b5cf6', 'CE' => '#64748b', 'SYS_ERR' => '#ec4899', 'QUEUED' => '#0284c7', 'RUNNING' => '#0284c7'];

$baseQuery = array_filter(['class_id' => $fClass, 'q' => $fSearch], static fn($value) => $value !== '');
$exportQuery = http_build_query(['type' => 'progress'] + $baseQuery);

$pageTitle = 'Tiến độ học viên';
$activeNav = 'prog';
require FE_DIR . '/partials/layout_head.php';
?>
<div style="max-width:1280px; margin:28px auto; padding:0 24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <h2>Tiến độ học viên (<?= $totalRows ?> học viên / <?= $totalProblems ?> bài)</h2>
        <a class="btn btn-success" href="<?= url('/backend/api/export_excel.php?' . $exportQuery) ?>">📥 Xuất Excel tiến độ</a>
    </div>

    <?php if ($distTotal > 0): ?>
    <div style="background:#fff; border-radius:12px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.06); margin-bottom:16px;">
        <strong style="font-size:0.9rem;">Tổng hợp kết quả (<?= $distTotal ?> lượt nộp trong phạm vi):</strong>
        <div style="display:flex; gap:6px; margin:10px 0; height:16px; border-radius:999px; overflow:hidden; background:#f1f5f9;">
            <?php foreach ($distRows as $v => $c): ?>
                <div title="<?= $v ?>: <?= $c ?>" style="width:<?= round($c / $distTotal * 100, 1) ?>%; background:<?= $distColors[$v] ?? '#94a3b8' ?>;"></div>
            <?php endforeach; ?>
        </div>
        <div style="display:flex; gap:12px; flex-wrap:wrap; font-size:0.82rem;">
            <?php foreach ($distRows as $v => $c): ?>
                <span><span style="display:inline-block; width:10px; height:10px; border-radius:2px; background:<?= $distColors[$v] ?? '#94a3b8' ?>;"></span> <strong><?= $v ?></strong>: <?= $c ?> (<?= round($c / $distTotal * 100, 1) ?>%)</span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <form method="GET" style="display:flex; gap:10px; margin-bottom:16px; background:#fff; padding:14px; border-radius:10px; flex-wrap:wrap;">
        <select name="class_id" class="form-control" style="max-width:220px;">
            <option value="">-- <?= $user['role'] === 'teacher' ? 'Mọi lớp của tôi' : 'Mọi lớp' ?> --</option>
            <?php foreach ($classes as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $fClass === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="q" class="form-control" style="max-width:240px;" placeholder="Tìm username / họ tên..." value="<?= e($fSearch) ?>">
        <button type="submit" class="btn btn-primary">Lọc</button>
        <a href="<?= url('/frontend/instructor/progress.php') ?>" class="btn" style="background:#e2e8f0; color:#334155;">Đặt lại</a>
    </form>

    <div style="background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.05); overflow:hidden;">
        <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
            <thead><tr style="background:#f8fafc; text-align:left;">
                <th style="padding:12px 16px;">Học viên</th><th style="padding:12px 16px;">Lớp</th>
                <th style="padding:12px 16px;">Đã giải (AC)</th><th style="padding:12px 16px;">Tiến độ</th>
                <th style="padding:12px 16px;">Tổng lượt nộp</th><th style="padding:12px 16px;">Nộp cuối</th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $r):
                    $pct = $totalProblems > 0 ? round((int)$r['solved'] / $totalProblems * 100) : 0;
                ?>
                <tr style="border-bottom:1px solid var(--cl-border);">
                    <td style="padding:12px 16px;"><strong><?= e($r['username']) ?></strong><br><small style="color:var(--cl-muted);"><?= e($r['full_name']) ?></small></td>
                    <td style="padding:12px 16px;"><span class="badge" style="background:#f1f5f9;"><?= e($r['class_names'] ?? '—') ?></span></td>
                    <td style="padding:12px 16px; font-weight:700;"><?= (int)$r['solved'] ?> / <?= $totalProblems ?></td>
                    <td style="padding:12px 16px; min-width:160px;">
                        <div style="background:#f1f5f9; border-radius:999px; height:10px; overflow:hidden;">
                            <div style="background:var(--cl-ac, #10b981); height:10px; width:<?= $pct ?>%;"></div>
                        </div>
                        <small style="color:var(--cl-muted);"><?= $pct ?>%</small>
                    </td>
                    <td style="padding:12px 16px;"><?= (int)$r['sub_total'] ?></td>
                    <td style="padding:12px 16px; color:var(--cl-muted); font-size:0.85rem;"><?= e($r['last_submit'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                <tr><td colspan="6" style="padding:24px; text-align:center; color:var(--cl-muted);">Không có học viên nào khớp bộ lọc.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php require FE_DIR . '/partials/pagination.php'; ?>
    </div>
</div>
<?php require FE_DIR . '/partials/layout_foot.php'; ?>
