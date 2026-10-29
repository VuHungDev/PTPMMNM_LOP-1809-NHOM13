<?php
// frontend/instructor/dataset_versions.php — Tạo phiên bản bộ test mới (teacher/admin)
// Chấm lại + đối chiếu tách sang rejudge.php; mỗi trang một chức năng.
require_once __DIR__ . '/../../backend/lib/Auth.php';
require_once __DIR__ . '/../../backend/lib/JudgeEngine.php';

$user = Auth::requireRole(['teacher', 'admin']);
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_version') {
    $problemId   = (int)$_POST['problem_id'];
    $timeLimit   = max(100, (int)$_POST['time_limit_ms']);
    $changelog   = trim($_POST['changelog']);
    $extraStdin  = trim($_POST['extra_stdin']);
    $extraExpect = trim($_POST['extra_expected']);

    $stmtCur = $pdo->prepare("SELECT * FROM problem_versions WHERE problem_id = ? ORDER BY version_number DESC LIMIT 1");
    $stmtCur->execute([$problemId]);
    $curVer = $stmtCur->fetch();

    if ($curVer) {
        $newVerNum = (int)$curVer['version_number'] + 1;
        $pdo->beginTransaction();

        $stmtNewVer = $pdo->prepare("
            INSERT INTO problem_versions (problem_id, version_number, description, input_desc, output_desc, sample_input, sample_output, time_limit_ms, memory_limit_kb, changelog, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtNewVer->execute([
            $problemId, $newVerNum, $curVer['description'], $curVer['input_desc'],
            $curVer['output_desc'], $curVer['sample_input'], $curVer['sample_output'],
            $timeLimit, $curVer['memory_limit_kb'], $changelog, $user['id'],
        ]);
        $newVerId = (int)$pdo->lastInsertId();

        $stmtOldCases = $pdo->prepare("SELECT * FROM test_cases WHERE problem_version_id = ? ORDER BY case_order ASC");
        $stmtOldCases->execute([$curVer['id']]);
        $oldCases = $stmtOldCases->fetchAll();

        $insCase = $pdo->prepare("INSERT INTO test_cases (problem_version_id, case_order, stdin_data, expected_output, is_public, is_boundary, points) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $order = 1;
        foreach ($oldCases as $c) {
            $insCase->execute([$newVerId, $order++, $c['stdin_data'], $c['expected_output'], $c['is_public'], $c['is_boundary'], $c['points']]);
        }

        if ($extraStdin !== '' && $extraExpect !== '') {
            $insCase->execute([$newVerId, $order, $extraStdin, $extraExpect, 0, 1, 20]);
        }

        $pdo->prepare("UPDATE problems SET current_version_id = ? WHERE id = ?")->execute([$newVerId, $problemId]);
        $pdo->commit();

        $msg = "Đã tạo thành công Bộ dữ liệu Phiên bản v{$newVerNum}.0 (ID #{$newVerId})! Các lần chấm trước vẫn được giữ nguyên.";
    }
}

$problems = $pdo->query("SELECT p.*, pv.version_number FROM problems p JOIN problem_versions pv ON pv.id = p.current_version_id ORDER BY p.id ASC")->fetchAll();

$versions = $pdo->query("
    SELECT pv.*, p.title AS problem_title,
           (SELECT COUNT(*) FROM test_cases tc WHERE tc.problem_version_id = pv.id) AS case_count
    FROM problem_versions pv JOIN problems p ON p.id = pv.problem_id
    ORDER BY pv.problem_id ASC, pv.version_number DESC LIMIT 30
")->fetchAll();

$pageTitle = 'Bộ Test & Phiên bản';
$activeNav = 'data';
require FE_DIR . '/partials/layout_head.php';
?>
<div style="max-width:1280px; margin:28px auto; padding:0 24px;">
    <?php if ($msg): ?>
        <div style="background:#dcfce7; color:#15803d; padding:14px 18px; border-radius:8px; margin-bottom:20px; font-weight:600;">
            ✔ <?= e($msg) ?>
        </div>
    <?php endif; ?>

    <div style="display:grid; grid-template-columns: 1fr 1.4fr; gap:24px; align-items:start;">
        <div style="background:#fff; padding:24px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
            <h3 style="margin-bottom:12px;">Tạo phiên bản bộ dữ liệu mới</h3>
            <p style="font-size:0.88rem; color:var(--cl-muted); margin-bottom:16px;">
                Khi cập nhật bộ test, hệ thống tạo một <code>problem_version</code> mới để không làm sai lệch lịch sử các bài đã nộp ở phiên bản cũ.
                Muốn chấm lại các bài cũ trên bộ test mới, sang trang <a href="<?= url('/frontend/instructor/rejudge.php') ?>">Chấm lại &amp; đối chiếu</a>.
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="create_version">
                <div class="form-group">
                    <label>Chọn bài tập</label>
                    <select name="problem_id" class="form-control">
                        <?php foreach ($problems as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"><?= e($p['title']) ?> (Hiện tại: v<?= (int)$p['version_number'] ?>.0)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Giới hạn chạy mới (ms)</label>
                    <input type="number" name="time_limit_ms" class="form-control" value="1000" required>
                </div>
                <div class="form-group">
                    <label>Ghi chú thay đổi (Changelog)</label>
                    <input type="text" name="changelog" class="form-control" value="Bổ sung test case biên chặt hơn" required>
                </div>
                <div class="form-group">
                    <label>Thêm Test Case Kín Mới — Đầu vào (Stdin)</label>
                    <textarea name="extra_stdin" class="form-control" rows="2" placeholder="VD: -1000000000 1000000000"></textarea>
                </div>
                <div class="form-group">
                    <label>Thêm Test Case Kín Mới — Đáp án chuẩn (Expected Output)</label>
                    <textarea name="extra_expected" class="form-control" rows="2" placeholder="VD: 0"></textarea>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">+ Phát hành Phiên bản Bộ dữ liệu mới</button>
            </form>
        </div>

        <div style="background:#fff; padding:24px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
            <h3 style="margin-bottom:12px;">Lịch sử phiên bản bộ test (30 mới nhất)</h3>
            <table style="width:100%; border-collapse:collapse; font-size:0.88rem;">
                <thead>
                    <tr style="background:#f8fafc; border-bottom:1px solid var(--cl-border); text-align:left;">
                        <th style="padding:10px;">Bài tập</th>
                        <th style="padding:10px;">Phiên bản</th>
                        <th style="padding:10px;">Số test</th>
                        <th style="padding:10px;">Giới hạn</th>
                        <th style="padding:10px;">Ghi chú thay đổi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($versions as $v): ?>
                    <tr style="border-bottom:1px solid var(--cl-border);">
                        <td style="padding:10px;"><?= e($v['problem_title']) ?></td>
                        <td style="padding:10px; font-weight:700;">v<?= (int)$v['version_number'] ?>.0</td>
                        <td style="padding:10px;"><?= (int)$v['case_count'] ?> ca</td>
                        <td style="padding:10px;"><?= (int)$v['time_limit_ms'] ?> ms</td>
                        <td style="padding:10px;"><?= e($v['changelog']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require FE_DIR . '/partials/layout_foot.php'; ?>
