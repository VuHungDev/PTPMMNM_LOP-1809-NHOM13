<?php
// frontend/instructor/rejudge.php — Chấm lại (Rejudge) + đối chiếu kết quả giữa các phiên bản (teacher/admin)
// Tách khỏi dataset_versions.php; trang kia chỉ lo tạo phiên bản bộ test mới.
require_once __DIR__ . '/../../backend/lib/Auth.php';
require_once __DIR__ . '/../../backend/lib/JudgeEngine.php';

$user = Auth::requireRole(['teacher', 'admin']);

$fProblem = (int)($_GET['problem_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$where = [];
$params = [];
if ($fProblem > 0) {
    $where[] = 's.problem_id = ?';
    $params[] = $fProblem;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM submissions s $whereSql");
$stmtCnt->execute($params);
$totalRows = (int)$stmtCnt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$submissions = $pdo->prepare("
    SELECT s.*, u.username, u.class_code, p.title AS problem_title, p.current_version_id, pv.version_number AS sub_ver_num,
           (SELECT GROUP_CONCAT(CONCAT(jr.run_number, '|', jr.trigger_type, '|', jr.verdict, '|', jr.pass_count, '|', jr.total_cases, '|', jr.score, '|', v.version_number) SEPARATOR ';;')
            FROM judging_runs jr JOIN problem_versions v ON v.id = jr.problem_version_id
            WHERE jr.submission_id = s.id ORDER BY jr.run_number ASC) AS runs_blob
    FROM submissions s
    JOIN users u ON u.id = s.user_id
    JOIN problems p ON p.id = s.problem_id
    JOIN problem_versions pv ON pv.id = s.problem_version_id
    $whereSql
    ORDER BY s.id DESC LIMIT $perPage OFFSET $offset
");
$submissions->execute($params);
$submissions = $submissions->fetchAll();

$problems = $pdo->query("SELECT p.id, p.title, pv.version_number FROM problems p JOIN problem_versions pv ON pv.id = p.current_version_id ORDER BY p.id ASC")->fetchAll();
$baseQuery = array_filter(['problem_id' => $fProblem ?: null]);

$pageTitle = 'Chấm lại & Đối chiếu';
$activeNav = 'rejudge';
require FE_DIR . '/partials/layout_head.php';
?>
<div style="max-width:1280px; margin:28px auto; padding:0 24px;">
    <h2 style="margin-bottom:6px;">Chấm lại (Rejudge) &amp; Đối chiếu kết quả</h2>
    <p style="color:var(--cl-muted); margin-bottom:20px;">
        Giữ nguyên mã nguồn và kết quả các lần chấm trước; tạo lượt chấm mới trên bộ dữ liệu hiện hành để đối chiếu trực tiếp.
    </p>

    <form method="GET" style="display:flex; gap:10px; margin-bottom:16px; background:#fff; padding:14px; border-radius:10px; align-items:end;">
        <div class="form-group" style="margin:0; min-width:260px;"><label>Lọc theo bài tập</label>
            <select name="problem_id" class="form-control">
                <option value="">-- Mọi bài tập --</option>
                <?php foreach ($problems as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $fProblem === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['title']) ?> (v<?= (int)$p['version_number'] ?>.0)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Lọc</button>
        <a href="?" class="btn" style="background:#e2e8f0; color:#334155;">Đặt lại</a>
    </form>

    <div style="background:#fff; padding:24px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
        <table style="width:100%; border-collapse:collapse; font-size:0.88rem;">
            <thead>
                <tr style="background:#f8fafc; border-bottom:1px solid var(--cl-border); text-align:left;">
                    <th style="padding:10px;">Mã nộp</th>
                    <th style="padding:10px;">Học viên</th>
                    <th style="padding:10px;">Bài tập</th>
                    <th style="padding:10px;">Lịch sử các lần chấm (Đối chiếu Runs)</th>
                    <th style="padding:10px; text-align:right;">Hành động</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($submissions as $s):
                    $runs = [];
                    if (!empty($s['runs_blob'])) {
                        foreach (explode(';;', $s['runs_blob']) as $chunk) {
                            $parts = explode('|', $chunk);
                            if (count($parts) === 7) {
                                $runs[] = $parts;
                            }
                        }
                    }
                ?>
                <tr style="border-bottom:1px solid var(--cl-border);">
                    <td style="padding:10px; font-weight:700;">#<?= (int)$s['id'] ?></td>
                    <td style="padding:10px;"><?= e($s['username']) ?><br><small style="color:var(--cl-muted);"><?= e($s['class_code']) ?></small></td>
                    <td style="padding:10px;"><?= e($s['problem_title']) ?></td>
                    <td style="padding:10px;">
                        <?php foreach ($runs as $r): ?>
                            <div style="margin-bottom:4px;">
                                <strong>Run #<?= (int)$r[0] ?></strong> (<?= e($r[1]) ?> — Bộ test <code>v<?= (int)$r[6] ?>.0</code>):
                                <span class="badge verdict-<?= e($r[2]) ?>"><?= e($r[2]) ?> (<?= (int)$r[3] ?>/<?= (int)$r[4] ?> ca — <?= (int)$r[5] ?>đ)</span>
                            </div>
                        <?php endforeach; ?>
                    </td>
                    <td style="padding:10px; text-align:right;">
                        <button class="btn btn-primary" style="padding:6px 12px; font-size:0.8rem;" data-aj-cmp="instructorPage" x-on:click="rejudgeSubmission(<?= (int)$s['id'] ?>, <?= (int)$s['current_version_id'] ?>)">
                            🔄 Chấm lại với Bộ test hiện hành
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php require FE_DIR . '/partials/pagination.php'; ?>
    </div>
</div>

<?php
$pageScripts = '<script type="module">import "' . url('/frontend/assets/js/pages/instructor.js') . '";</script>';
require FE_DIR . '/partials/layout_foot.php';
?>
