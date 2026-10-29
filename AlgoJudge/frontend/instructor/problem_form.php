<?php
// frontend/instructor/problem_form.php — Thêm / sửa bài tập (tách khỏi problems.php).
// Trang này chỉ lo form tạo + sửa; problems.php chỉ lo danh sách + ẩn/hiện.
require_once __DIR__ . '/../../backend/lib/Auth.php';
require_once __DIR__ . '/../../backend/lib/JudgeEngine.php';
$user = Auth::requireRole(['teacher', 'admin']);
$msg = '';
$err = '';

$editing = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmtE = $pdo->prepare("
        SELECT p.*, pv.* FROM problems p
        JOIN problem_versions pv ON pv.id = p.current_version_id
        WHERE p.id = ?
    ");
    $stmtE->execute([$editId]);
    $editing = $stmtE->fetch();
    if ($editing) {
        $stmtC = $pdo->prepare("SELECT * FROM test_cases WHERE problem_version_id = ? ORDER BY case_order ASC");
        $stmtC->execute([$editing['current_version_id']]);
        $editing['cases'] = $stmtC->fetchAll();
    }
}

$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'create' || $action === 'update')) {
    $slug = trim($_POST['slug'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $topic = trim($_POST['topic'] ?? 'Cơ bản');
    $difficulty = in_array($_POST['difficulty'] ?? '', ['Easy', 'Medium', 'Hard'], true) ? $_POST['difficulty'] : 'Easy';
    $suggestLang = $_POST['suggested_lang'] ?? 'python';
    if (!JudgeEngine::isSupported($suggestLang)) {
        $suggestLang = 'python';
    }
    $desc = trim($_POST['description'] ?? '');
    $inDesc = trim($_POST['input_desc'] ?? '');
    $outDesc = trim($_POST['output_desc'] ?? '');
    $sampleIn = trim($_POST['sample_input'] ?? '');
    $sampleOut = trim($_POST['sample_output'] ?? '');
    $timeLimit = max(100, (int)($_POST['time_limit_ms'] ?? 1000));
    $testStdin = $_POST['test_stdin'] ?? [];
    $testExpected = $_POST['test_expected'] ?? [];
    $testPublic = $_POST['test_public'] ?? [];
    $testBoundary = $_POST['test_boundary'] ?? [];

    if (!preg_match('/^[a-z0-9-]{3,100}$/', $slug)) {
        $err = 'Slug chỉ gồm chữ thường, số, gạch ngang (3–100 ký tự).';
    } elseif ($title === '' || $desc === '' || $sampleIn === '' || $sampleOut === '') {
        $err = 'Vui lòng nhập đầy đủ tiêu đề, mô tả và ví dụ mẫu.';
    } else {
        $cases = [];
        foreach ($testStdin as $i => $sin) {
            $sin = trim($sin);
            $sexp = trim($testExpected[$i] ?? '');
            if ($sin === '' || $sexp === '') {
                continue;
            }
            $cases[] = [$sin, $sexp, isset($testPublic[$i]) ? 1 : 0, isset($testBoundary[$i]) ? 1 : 0];
        }
        if (count($cases) < 1) {
            $err = 'Cần ít nhất 1 test case chấm.';
        } else {
            try {
                $pdo->beginTransaction();
                if ($action === 'create') {
                    $chk = $pdo->prepare("SELECT id FROM problems WHERE slug = ?");
                    $chk->execute([$slug]);
                    if ($chk->fetch()) {
                        throw new Exception('Slug đã tồn tại, chọn slug khác.');
                    }
                    $pdo->prepare("INSERT INTO problems (slug, title, topic, difficulty, suggested_lang, created_by) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$slug, $title, $topic, $difficulty, $suggestLang, $user['id']]);
                    $problemId = (int)$pdo->lastInsertId();
                    $verNum = 1;
                    $changelog = 'Phiên bản khởi tạo';
                } else {
                    $problemId = (int)($_POST['problem_id'] ?? 0);
                    $stmtCur = $pdo->prepare("SELECT * FROM problem_versions WHERE problem_id = ? ORDER BY version_number DESC LIMIT 1");
                    $stmtCur->execute([$problemId]);
                    if (!$stmtCur->fetch()) {
                        throw new Exception('Không tìm thấy bài tập.');
                    }
                    $pdo->prepare("UPDATE problems SET slug = ?, title = ?, topic = ?, difficulty = ?, suggested_lang = ? WHERE id = ?")
                        ->execute([$slug, $title, $topic, $difficulty, $suggestLang, $problemId]);
                    $verNum = (int)$pdo->query("SELECT COALESCE(MAX(version_number),0)+1 FROM problem_versions WHERE problem_id = $problemId")->fetchColumn();
                    $changelog = trim($_POST['changelog'] ?? 'Cập nhật đề / test');
                }
                $pdo->prepare("INSERT INTO problem_versions (problem_id, version_number, description, input_desc, output_desc, sample_input, sample_output, time_limit_ms, changelog, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$problemId, $verNum, $desc, $inDesc, $outDesc, $sampleIn, $sampleOut, $timeLimit, $changelog, $user['id']]);
                $verId = (int)$pdo->lastInsertId();
                $insCase = $pdo->prepare("INSERT INTO test_cases (problem_version_id, case_order, stdin_data, expected_output, is_public, is_boundary, points) VALUES (?, ?, ?, ?, ?, ?, 20)");
                foreach ($cases as $i => $c) {
                    $insCase->execute([$verId, $i + 1, $c[0], $c[1], $c[2], $c[3]]);
                }
                $pdo->prepare("UPDATE problems SET current_version_id = ? WHERE id = ?")->execute([$verId, $problemId]);
                $pdo->commit();
                header('Location: ' . url('/frontend/instructor/problems.php'));
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $err = $e->getMessage();
                // Giữ lại dữ liệu đã nhập để không mất khi lỗi
                $editing = [
                    'id' => $action === 'update' ? (int)($_POST['problem_id'] ?? 0) : 0,
                    'slug' => $slug, 'title' => $title, 'topic' => $topic, 'difficulty' => $difficulty,
                    'suggested_lang' => $suggestLang, 'description' => $desc, 'input_desc' => $inDesc,
                    'output_desc' => $outDesc, 'sample_input' => $sampleIn, 'sample_output' => $sampleOut,
                    'time_limit_ms' => $timeLimit,
                    'cases' => array_map(fn($c, $i) => [
                        'stdin_data' => $c[0], 'expected_output' => $c[1],
                        'is_public' => $c[2], 'is_boundary' => $c[3],
                    ], $cases, array_keys($cases)),
                ];
            }
        }
    }
}

$pageTitle = $editing ? 'Sửa bài tập #' . (int)$editing['id'] : 'Thêm bài tập mới';
$activeNav = 'prob_new';
require FE_DIR . '/partials/layout_head.php';
$ecases = $editing['cases'] ?? [['stdin_data' => '', 'expected_output' => '', 'is_public' => 1, 'is_boundary' => 0]];
?>
<div style="max-width:900px; margin:28px auto; padding:0 24px;">
    <h2 style="margin-bottom:6px;"><?= $editing ? 'Sửa bài tập #' . (int)$editing['id'] . ' <small style="color:var(--cl-muted);">(lưu sẽ tạo phiên bản mới)</small>' : 'Thêm bài tập mới' ?></h2>
    <p style="margin-bottom:16px;"><a href="<?= url('/frontend/instructor/problems.php') ?>">← Về danh sách bài tập</a></p>

    <?php if ($err): ?><div style="background:#fee2e2; color:#b91c1c; padding:12px 18px; border-radius:8px; margin-bottom:16px; font-weight:600;">✘ <?= e($err) ?></div><?php endif; ?>

    <div style="background:#fff; padding:20px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
        <form method="POST">
            <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
            <?php if ($editing && ($editing['id'] ?? 0) > 0): ?><input type="hidden" name="problem_id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
            <div class="form-group"><label>Slug (duy nhất)</label><input type="text" name="slug" class="form-control" required value="<?= e($editing['slug'] ?? '') ?>"></div>
            <div class="form-group"><label>Tiêu đề</label><input type="text" name="title" class="form-control" required value="<?= e($editing['title'] ?? '') ?>"></div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <div class="form-group"><label>Chủ đề</label>
                    <select name="topic" class="form-control">
                        <?php foreach (['Cơ bản', 'Rẽ nhánh', 'Vòng lặp', 'Số học', 'Quy hoạch động', 'Mảng', 'Xử lý chuỗi', 'Tìm kiếm'] as $t): ?>
                            <option <?= ($editing['topic'] ?? '') === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Độ khó</label>
                    <select name="difficulty" class="form-control">
                        <?php foreach (['Easy', 'Medium', 'Hard'] as $d): ?>
                            <option <?= ($editing['difficulty'] ?? 'Easy') === $d ? 'selected' : '' ?>><?= e($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group"><label>Mô tả</label><textarea name="description" class="form-control" rows="3" required><?= e($editing['description'] ?? '') ?></textarea></div>
            <div class="form-group"><label>Mô tả đầu vào</label><textarea name="input_desc" class="form-control" rows="2"><?= e($editing['input_desc'] ?? '') ?></textarea></div>
            <div class="form-group"><label>Mô tả đầu ra</label><textarea name="output_desc" class="form-control" rows="2"><?= e($editing['output_desc'] ?? '') ?></textarea></div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <div class="form-group"><label>Ví dụ input</label><textarea name="sample_input" class="form-control" rows="2" required><?= e($editing['sample_input'] ?? '') ?></textarea></div>
                <div class="form-group"><label>Ví dụ output</label><textarea name="sample_output" class="form-control" rows="2" required><?= e($editing['sample_output'] ?? '') ?></textarea></div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <div class="form-group"><label>Ngôn ngữ gợi ý</label>
                    <select name="suggested_lang" class="form-control">
                        <?php foreach (JudgeEngine::LANGUAGES as $key => $meta): ?>
                            <option value="<?= e($key) ?>" <?= ($editing['suggested_lang'] ?? 'python') === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Giới hạn chạy (ms)</label><input type="number" name="time_limit_ms" class="form-control" value="<?= (int)($editing['time_limit_ms'] ?? 1000) ?>"></div>
            </div>
            <?php if ($editing && ($editing['id'] ?? 0) > 0): ?>
            <div class="form-group"><label>Changelog phiên bản mới</label><input type="text" name="changelog" class="form-control" value="Cập nhật đề / test"></div>
            <?php endif; ?>
            <label style="font-weight:700; font-size:0.9rem;">Bộ test chấm (tối thiểu 1 ca):</label>
            <div id="case-list">
                <?php foreach ($ecases as $i => $c): ?>
                <div class="case-row" style="border:1px solid var(--cl-border); border-radius:8px; padding:10px; margin:8px 0;">
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                        <textarea name="test_stdin[]" class="form-control" rows="2" placeholder="Stdin"><?= e($c['stdin_data']) ?></textarea>
                        <textarea name="test_expected[]" class="form-control" rows="2" placeholder="Expected"><?= e($c['expected_output']) ?></textarea>
                    </div>
                    <label style="font-size:0.82rem; margin-right:12px;"><input type="checkbox" name="test_public[<?= $i ?>]" value="1" <?= $c['is_public'] ? 'checked' : '' ?>> Công khai</label>
                    <label style="font-size:0.82rem;"><input type="checkbox" name="test_boundary[<?= $i ?>]" value="1" <?= $c['is_boundary'] ? 'checked' : '' ?>> Dữ liệu biên</label>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn" style="background:#e2e8f0; margin-bottom:12px;" data-aj-cmp="instructorPage" data-case-start="<?= count($ecases) ?>" x-on:click="addCaseRow()">+ Thêm test case</button>
            <div style="display:flex; gap:10px;">
                <button type="submit" class="btn btn-primary" style="flex:1; justify-content:center;"><?= $editing && ($editing['id'] ?? 0) > 0 ? 'Lưu (tạo phiên bản mới)' : 'Tạo bài tập' ?></button>
                <a class="btn" style="background:#e2e8f0;" href="<?= url('/frontend/instructor/problems.php') ?>">Hủy</a>
            </div>
        </form>
    </div>
</div>

<?php
$pageScripts = '<script type="module">import "' . url('/frontend/assets/js/pages/instructor.js') . '";</script>';
require FE_DIR . '/partials/layout_foot.php';
?>
