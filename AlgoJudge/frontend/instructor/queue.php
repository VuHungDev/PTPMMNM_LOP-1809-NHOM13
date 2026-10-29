<?php
// frontend/instructor/queue.php — Giám sát hàng đợi chấm (teacher/admin)
// Thấy các run QUEUED/RUNNING/SYS_ERROR, thử lại run lỗi, phát hiện run kẹt.
require_once __DIR__ . '/../../backend/lib/Auth.php';
$user = Auth::requireRole(['teacher', 'admin']);

$stuckMinutes = 5;
$runs = $pdo->query("
    SELECT jr.*, s.language, u.username, u.class_code, p.title AS problem_title, pv.version_number,
           TIMESTAMPDIFF(MINUTE, jr.created_at, NOW()) AS age_min
    FROM judging_runs jr
    JOIN submissions s ON s.id = jr.submission_id
    JOIN users u ON u.id = s.user_id
    JOIN problems p ON p.id = s.problem_id
    JOIN problem_versions pv ON pv.id = jr.problem_version_id
    WHERE jr.status IN ('QUEUED', 'RUNNING', 'SYS_ERROR')
    ORDER BY jr.id DESC LIMIT 30
")->fetchAll();

$stats = $pdo->query("
    SELECT
      SUM(status = 'QUEUED') AS queued,
      SUM(status = 'RUNNING') AS running,
      SUM(status = 'SYS_ERROR') AS syserr,
      SUM(status = 'COMPLETED') AS completed
    FROM judging_runs
")->fetch();

$pageTitle = 'Hàng đợi chấm';
$activeNav = 'queue';
require FE_DIR . '/partials/layout_head.php';
?>
<div style="max-width:1280px; margin:28px auto; padding:0 24px;">
    <h2 style="margin-bottom:6px;">Giám sát Hàng đợi chấm</h2>
    <p style="color:var(--cl-muted); margin-bottom:16px;">
        Phân biệt <strong>sai bài (WA/RE/TLE/CE)</strong> với <strong>lỗi hệ thống (SYS_ERR)</strong>:
        run lỗi hệ thống được thử lại mà không chấm người học sai, lịch sử cũ giữ nguyên.
    </p>

    <div style="display:flex; gap:12px; margin-bottom:20px;">
        <div style="background:#fff; padding:14px 20px; border-radius:10px; flex:1; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,0.06);">
            <div style="font-size:1.5rem; font-weight:800; color:#0369a1;"><?= (int)$stats['queued'] ?></div>
            <div style="font-size:0.82rem; color:var(--cl-muted);">ĐANG CHỜ (QUEUED)</div>
        </div>
        <div style="background:#fff; padding:14px 20px; border-radius:10px; flex:1; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,0.06);">
            <div style="font-size:1.5rem; font-weight:800; color:#b45309;"><?= (int)$stats['running'] ?></div>
            <div style="font-size:0.82rem; color:var(--cl-muted);">ĐANG CHẤM (RUNNING)</div>
        </div>
        <div style="background:#fff; padding:14px 20px; border-radius:10px; flex:1; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,0.06);">
            <div style="font-size:1.5rem; font-weight:800; color:#be185d;"><?= (int)$stats['syserr'] ?></div>
            <div style="font-size:0.82rem; color:var(--cl-muted);">LỖI HỆ THỐNG (cần thử lại)</div>
        </div>
        <div style="background:#fff; padding:14px 20px; border-radius:10px; flex:1; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,0.06);">
            <div style="font-size:1.5rem; font-weight:800; color:#047857;"><?= (int)$stats['completed'] ?></div>
            <div style="font-size:0.82rem; color:var(--cl-muted);">HOÀN TẤT (COMPLETED)</div>
        </div>
    </div>

    <div style="background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.05); overflow:hidden;">
        <table style="width:100%; border-collapse:collapse; font-size:0.88rem;">
            <thead><tr style="background:#f8fafc; text-align:left;">
                <th style="padding:10px;">Run ID</th><th style="padding:10px;">Bài nộp</th><th style="padding:10px;">Học viên</th>
                <th style="padding:10px;">Bài / Bộ test</th><th style="padding:10px;">Loại / Trạng thái</th>
                <th style="padding:10px;">Tuổi</th><th style="padding:10px; text-align:right;">Thao tác</th>
            </tr></thead>
            <tbody>
                <?php foreach ($runs as $r):
                    $stuck = in_array($r['status'], ['QUEUED', 'RUNNING'], true) && (int)$r['age_min'] >= $stuckMinutes;
                ?>
                <tr style="border-bottom:1px solid var(--cl-border); <?= $stuck ? 'background:#fffbeb;' : '' ?>">
                    <td style="padding:10px; font-weight:700;">#<?= (int)$r['id'] ?> <small style="color:var(--cl-muted);">(lần <?= (int)$r['run_number'] ?>)</small></td>
                    <td style="padding:10px;">#<?= (int)$r['submission_id'] ?> <code><?= e($r['language']) ?></code></td>
                    <td style="padding:10px;"><?= e($r['username']) ?><br><small style="color:var(--cl-muted);"><?= e($r['class_code']) ?></small></td>
                    <td style="padding:10px;"><?= e($r['problem_title']) ?><br><small style="color:var(--cl-muted);">test v<?= (int)$r['version_number'] ?>.0</small></td>
                    <td style="padding:10px;"><code><?= e($r['trigger_type']) ?></code><br><span class="badge verdict-<?= e($r['verdict']) ?>"><?= e($r['status']) ?></span></td>
                    <td style="padding:10px;"><?= (int)$r['age_min'] ?> phút<?= $stuck ? '<br><span class="badge verdict-TLE">KẸT?</span>' : '' ?></td>
                    <td style="padding:10px; text-align:right;">
                        <button class="btn btn-primary" style="padding:6px 12px; font-size:0.8rem;" data-aj-cmp="instructorPage" x-on:click="retryRun(<?= (int)$r['id'] ?>)">🔁 Thử lại</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$runs): ?>
                <tr><td colspan="7" style="padding:24px; text-align:center; color:var(--cl-muted);">Hàng đợi trống — không có run chờ, đang chấm hay lỗi hệ thống. ✔</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p style="color:var(--cl-muted); font-size:0.85rem; margin-top:12px;">
        Run kẹt (QUEUED/RUNNING quá <?= $stuckMinutes ?> phút, thường do worker dừng đột ngột) cũng được thử lại bằng cùng nút — hệ thống tạo lượt chấm mới (SYS_RETRY) thay vì chấm đè.
    </p>
</div>

<?php
$pageScripts = '<script type="module">import "' . url('/frontend/assets/js/pages/instructor.js') . '";</script>';
require FE_DIR . '/partials/layout_foot.php';
?>
