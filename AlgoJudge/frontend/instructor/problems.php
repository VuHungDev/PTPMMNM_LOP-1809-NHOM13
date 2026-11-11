<?php
// frontend/instructor/problems.php — Danh sách bài tập + bật/tắt hiển thị (teacher/admin)
// Thêm/sửa bài tách sang problem_form.php; mỗi trang một chức năng.
require_once __DIR__ . '/../../backend/lib/Auth.php';
require_once __DIR__ . '/../../backend/lib/AuditLog.php';
$user = Auth::requireRole(['teacher', 'admin']);
$msg = '';
$msgOk = true;

$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['toggle', 'delete'], true)) {
    csrf_verify();
    $pid = (int)($_POST['problem_id'] ?? 0);
    if ($action === 'delete' && $user['role'] !== 'admin') {
        http_response_code(403);
        $msg = 'Chỉ quản trị viên mới được xóa bài tập.';
        $msgOk = false;
    } else {
        $ownsTransaction = false;
        try {
            if ($pdo->inTransaction()) throw new LogicException('Problem mutation requires its own transaction.');
            $pdo->beginTransaction();
            $ownsTransaction = true;
            $stmt = $pdo->prepare('SELECT title, is_active FROM problems WHERE id = ? FOR UPDATE');
            $stmt->execute([$pid]);
            $problem = $stmt->fetch();
            if (!$problem) throw new DomainException("Không tìm thấy bài #$pid.");
            if ($action === 'toggle') {
                $newActive = (int)$problem['is_active'] === 1 ? 0 : 1;
                $pdo->prepare('UPDATE problems SET is_active = ? WHERE id = ?')->execute([$newActive, $pid]);
                $auditAction = $newActive ? 'problem.enable' : 'problem.disable';
                $msg = "Đã đổi trạng thái hiển thị bài #$pid.";
            } else {
                // submissions.problem_id không có ON DELETE CASCADE → xóa bài nộp trước
                $pdo->prepare('DELETE FROM submissions WHERE problem_id = ?')->execute([$pid]);
                $pdo->prepare('DELETE FROM problems WHERE id = ?')->execute([$pid]);
                $auditAction = 'problem.delete';
                $msg = "Đã xóa bài #$pid — {$problem['title']}.";
            }
            AuditLog::record($pdo, $user, $auditAction, 'problem', $pid, ['source' => 'ui']);
            $pdo->commit();
            $ownsTransaction = false;
        } catch (Throwable $ex) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            $msg = $ex instanceof DomainException ? $ex->getMessage() : "Thao tác với bài #$pid thất bại, chưa có gì bị thay đổi.";
            $msgOk = false;
        }
    }
}

// Danh sách + phân trang
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$totalRows = (int)$pdo->query("SELECT COUNT(*) FROM problems")->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$problems = $pdo->query("
    SELECT p.*, pv.version_number, pv.time_limit_ms,
           (SELECT COUNT(*) FROM test_cases tc WHERE tc.problem_version_id = p.current_version_id) AS case_count,
           (SELECT COUNT(*) FROM submissions s WHERE s.problem_id = p.id) AS sub_count
    FROM problems p JOIN problem_versions pv ON pv.id = p.current_version_id
    ORDER BY p.id ASC LIMIT $perPage OFFSET $offset
")->fetchAll();
$baseQuery = [];

$editing = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $stmtE = $pdo->prepare("
        SELECT p.*, pv.* FROM problems p
        JOIN problem_versions pv ON pv.id = p.current_version_id
        WHERE p.id = ?
    ");
    $stmtE->execute([$eid]);
    $editing = $stmtE->fetch();
    if ($editing) {
        $stmtC = $pdo->prepare("SELECT * FROM test_cases WHERE problem_version_id = ? ORDER BY case_order ASC");
        $stmtC->execute([$editing['current_version_id']]);
        $editing['cases'] = $stmtC->fetchAll();
    }
}

$pageTitle = 'Danh sách Bài tập';
$activeNav = 'probs';
require FE_DIR . '/partials/layout_head.php';
?>
<div style="max-width:1280px; margin:28px auto; padding:0 24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <h2>Danh sách bài tập (<?= $totalRows ?>)</h2>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a class="btn btn-success" href="<?= url('/backend/api/export_excel.php?type=problems') ?>">📥 Xuất Excel</a>
            <a class="btn btn-primary" href="<?= url('/frontend/instructor/problem_form.php') ?>">+ Thêm bài tập</a>
        </div>
    </div>
    <?php if ($msg): ?><div style="background:<?= $msgOk ? '#dcfce7; color:#15803d' : '#fee2e2; color:#b91c1c' ?>; padding:12px 18px; border-radius:8px; margin-bottom:16px; font-weight:600;"><?= $msgOk ? '✔' : '✖' ?> <?= e($msg) ?></div><?php endif; ?>

    <div style="background:#fff; padding:20px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
        <table style="width:100%; border-collapse:collapse; font-size:0.86rem;">
            <thead><tr style="background:#f8fafc; text-align:left;">
                <th style="padding:8px;">ID</th><th style="padding:8px;">Tiêu đề</th><th style="padding:8px;">Chủ đề / Độ khó</th>
                <th style="padding:8px;">Test</th><th style="padding:8px;">Nộp</th><th style="padding:8px;">Trạng thái</th><th style="padding:8px; text-align:right;">Thao tác</th>
            </tr></thead>
            <tbody>
                <?php foreach ($problems as $p): ?>
                <tr style="border-bottom:1px solid var(--cl-border);">
                    <td style="padding:8px; font-weight:700;">#<?= (int)$p['id'] ?></td>
                    <td style="padding:8px;"><?= e($p['title']) ?><br><small style="color:var(--cl-muted);"><?= e($p['slug']) ?> · v<?= (int)$p['version_number'] ?>.0</small></td>
                    <td style="padding:8px;"><?= e($p['topic']) ?> / <span class="badge badge-<?= e($p['difficulty']) ?>"><?= e($p['difficulty']) ?></span></td>
                    <td style="padding:8px;"><?= (int)$p['case_count'] ?> ca</td>
                    <td style="padding:8px;"><?= (int)$p['sub_count'] ?></td>
                    <td style="padding:8px;"><?= $p['is_active'] ? '<span class="badge verdict-AC">Hiện</span>' : '<span class="badge verdict-CE">Ẩn</span>' ?></td>
                    <td style="padding:8px; text-align:right; white-space:nowrap;">
                        <a class="btn btn-primary" style="padding:4px 10px; font-size:0.78rem;" href="<?= url('/frontend/instructor/problem_form.php?edit=' . (int)$p['id']) ?>">Sửa</a>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Đổi trạng thái hiển thị bài này?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="problem_id" value="<?= (int)$p['id'] ?>">
                            <button class="btn" style="background:#e2e8f0; padding:4px 10px; font-size:0.78rem;"><?= $p['is_active'] ? 'Ẩn' : 'Hiện' ?></button>
                        </form>
                        <?php if ($user['role'] === 'admin'): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('XÓA VĨNH VIỄN bài #<?= (int)$p['id'] ?> cùng toàn bộ bài nộp? Không thể hoàn tác.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="problem_id" value="<?= (int)$p['id'] ?>">
                            <button class="btn" style="background:#fee2e2; color:#b91c1c; padding:4px 10px; font-size:0.78rem;">Xóa</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php require FE_DIR . '/partials/pagination.php'; ?>
    </div>
</div>

<?php require FE_DIR . '/partials/layout_foot.php'; ?>
