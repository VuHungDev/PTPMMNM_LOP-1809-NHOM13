<?php
// frontend/submissions.php — Tra cứu lịch sử bài nộp: lọc + phân trang + xuất Excel + xem mã theo quyền
require_once __DIR__ . '/../backend/lib/Auth.php';
$viewer = Auth::requireLogin();

$fProblem = (int)($_GET['problem_id'] ?? 0);
$fVerdict = $_GET['verdict'] ?? '';
$fClass = trim($_GET['class_code'] ?? '');
$fUser = trim($_GET['username'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$allowedVerdicts = ['QUEUED', 'RUNNING', 'AC', 'WA', 'TLE', 'RE', 'CE', 'SYS_ERR'];
if ($fVerdict !== '' && !in_array($fVerdict, $allowedVerdicts, true)) {
    $fVerdict = '';
}

// Phạm vi dữ liệu: student chỉ thấy bài của mình,
// teacher thấy bài của lớp mình, admin thấy tất cả (lọc theo lớp nếu có)
$where = [];
$params = [];
if ($viewer['role'] === 'student') {
    $where[] = 's.user_id = ?';
    $params[] = $viewer['id'];
} elseif ($viewer['role'] === 'teacher') {
    if ($viewer['class_code'] !== 'ALL') {
        $where[] = 'u.class_code = ?';
        $params[] = $viewer['class_code'];
    }
} elseif ($fClass !== '') {
    $where[] = 'u.class_code = ?';
    $params[] = $fClass;
}
if ($fProblem > 0) {
    $where[] = 's.problem_id = ?';
    $params[] = $fProblem;
}
if ($fVerdict !== '') {
    $where[] = 's.final_verdict = ?';
    $params[] = $fVerdict;
}
if ($fUser !== '') {
    $where[] = 'u.username LIKE ?';
    $params[] = '%' . $fUser . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmtCnt = $pdo->prepare("
    SELECT COUNT(*) FROM submissions s
    JOIN users u ON u.id = s.user_id
    JOIN problems p ON p.id = s.problem_id
    $whereSql
");
$stmtCnt->execute($params);
$totalRows = (int)$stmtCnt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT s.*, u.id AS owner_id, u.username, u.full_name, u.class_code,
           p.title AS problem_title, pv.version_number
    FROM submissions s
    JOIN users u ON u.id = s.user_id
    JOIN problems p ON p.id = s.problem_id
    JOIN problem_versions pv ON pv.id = s.problem_version_id
    $whereSql
    ORDER BY s.id DESC LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$list = $stmt->fetchAll();

$problems = $pdo->query("SELECT id, title FROM problems WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
$classes = $pdo->query("SELECT DISTINCT class_code FROM users ORDER BY class_code ASC")->fetchAll();

$exportQuery = http_build_query(array_filter([
    'type' => 'submissions',
    'problem_id' => $fProblem ?: null,
    'verdict' => $fVerdict ?: null,
    'class_code' => $fClass ?: null,
]));
$baseQuery = array_filter(['problem_id' => $fProblem ?: null, 'verdict' => $fVerdict ?: null, 'class_code' => $fClass ?: null, 'username' => $fUser ?: null]);

$pageTitle = 'Lịch sử bài nộp';
$activeNav = 'subs';
$user = $viewer; // layout_head + sidebar dùng $user
require FE_DIR . '/partials/layout_head.php';
?>
<div style="max-width:1280px; margin:28px auto; padding:0 24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <div>
            <h2 style="margin-bottom:6px;">Tra cứu Lịch sử Bài nộp (<?= $totalRows ?> bản)</h2>
            <p style="color:var(--cl-muted);">
                Bạn đang đăng nhập: <strong><?= e($viewer['username']) ?></strong> (<?= e($viewer['role']) ?> — Lớp <?= e($viewer['class_code']) ?>).
            </p>
        </div>
        <a class="btn btn-success" href="<?= url('/backend/api/export_excel.php?' . $exportQuery) ?>">📥 Xuất Excel (lọc hiện tại)</a>
    </div>

    <form method="GET" style="display:flex; gap:10px; margin-bottom:16px; background:#fff; padding:14px; border-radius:10px; box-shadow:0 1px 3px rgba(0,0,0,0.06); flex-wrap:wrap;">
        <select name="problem_id" class="form-control" style="max-width:280px;">
            <option value="0">-- Mọi bài tập --</option>
            <?php foreach ($problems as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $fProblem === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['title']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="verdict" class="form-control" style="max-width:160px;">
            <option value="">-- Mọi kết quả --</option>
            <?php foreach ($allowedVerdicts as $v): ?>
                <option value="<?= $v ?>" <?= $fVerdict === $v ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($viewer['role'] === 'admin'): ?>
        <select name="class_code" class="form-control" style="max-width:160px;">
            <option value="">-- Mọi lớp --</option>
            <?php foreach ($classes as $c): ?>
                <option value="<?= e($c['class_code']) ?>" <?= $fClass === $c['class_code'] ? 'selected' : '' ?>><?= e($c['class_code']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <input type="text" name="username" class="form-control" style="max-width:180px;" placeholder="Tìm username..." value="<?= e($fUser) ?>">
        <button type="submit" class="btn btn-primary">Lọc</button>
        <a href="<?= url('/frontend/submissions.php') ?>" class="btn" style="background:#e2e8f0; color:#334155;">Đặt lại</a>
    </form>

    <div style="background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.05); overflow:hidden;">
        <table style="width:100%; border-collapse:collapse; text-align:left; font-size:0.9rem;">
            <thead>
                <tr style="background:#f8fafc; border-bottom:1px solid var(--cl-border);">
                    <th style="padding:12px 16px;">ID</th>
                    <th style="padding:12px 16px;">Người nộp</th>
                    <th style="padding:12px 16px;">Lớp</th>
                    <th style="padding:12px 16px;">Bài tập</th>
                    <th style="padding:12px 16px;">Ngôn ngữ / Phiên bản</th>
                    <th style="padding:12px 16px;">Kết quả</th>
                    <th style="padding:12px 16px;">Số ca đúng</th>
                    <th style="padding:12px 16px; text-align:right;">Quyền xem Mã nguồn</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($list as $row):
                    $allowed = Auth::canViewSubmission($viewer, ['id' => $row['owner_id'], 'class_code' => $row['class_code']]);
                ?>
                <tr style="border-bottom:1px solid var(--cl-border);">
                    <td style="padding:12px 16px; font-weight:700;">#<?= (int)$row['id'] ?></td>
                    <td style="padding:12px 16px;"><?= e($row['full_name']) ?> (<code><?= e($row['username']) ?></code>)</td>
                    <td style="padding:12px 16px;"><span class="badge" style="background:#f1f5f9;"><?= e($row['class_code']) ?></span></td>
                    <td style="padding:12px 16px; font-weight:600;"><?= e($row['problem_title']) ?></td>
                    <td style="padding:12px 16px;"><code><?= e($row['language']) ?></code> • v<?= (int)$row['version_number'] ?>.0</td>
                    <td style="padding:12px 16px;"><span class="badge verdict-<?= e($row['final_verdict']) ?>"><?= e($row['final_verdict']) ?></span></td>
                    <td style="padding:12px 16px;"><?= (int)$row['pass_count'] ?> / <?= (int)$row['total_cases'] ?></td>
                    <td style="padding:12px 16px; text-align:right;">
                        <?php if ($allowed): ?>
                            <button class="btn btn-primary" style="padding:5px 12px; font-size:0.8rem;" data-aj-cmp="submissionsPage" x-on:click="viewSourceModal(<?= (int)$row['id'] ?>)">Xem Mã &amp; Lịch sử Run</button>
                        <?php else: ?>
                            <span style="color:#94a3b8; font-size:0.82rem;">🔒 Mã riêng tư (Đã khóa)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$list): ?>
                <tr><td colspan="8" style="padding:24px; text-align:center; color:var(--cl-muted);">Không có bài nộp nào khớp bộ lọc.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php require FE_DIR . '/partials/pagination.php'; ?>
    </div>
</div>

<div id="code-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:760px; border-radius:12px; padding:24px; max-height:85vh; overflow-y:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h3 id="modal-title">Chi tiết bài nộp</h3>
            <button class="btn" onclick="document.getElementById('code-modal').style.display='none'">Đóng ✕</button>
        </div>
        <pre id="modal-code" style="background:#1e1e2e; color:#cdd6f4; padding:16px; border-radius:8px; overflow-x:auto; font-family:monospace; margin-bottom:16px;"></pre>
        <div id="modal-cases"></div>
    </div>
</div>

<?php
$pageScripts = '<script type="module">import "' . url('/frontend/assets/js/pages/submissions.js') . '";</script>';
require FE_DIR . '/partials/layout_foot.php';
?>
