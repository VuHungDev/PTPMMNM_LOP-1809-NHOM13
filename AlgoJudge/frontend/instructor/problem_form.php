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
    $title = trim($_POST['title'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    if ($slug === '' && $title !== '') {
        $t = mb_strtolower($title, 'UTF-8');
        $t = preg_replace('/[áàảãạăắằẳẵặâấầẩẫậ]/u', 'a', $t);
        $t = preg_replace('/[éèẻẽẹêếềểễệ]/u', 'e', $t);
        $t = preg_replace('/[íìỉĩị]/u', 'i', $t);
        $t = preg_replace('/[óòỏõọôốồổỗộơớờởỡợ]/u', 'o', $t);
        $t = preg_replace('/[úùủũụưứừửữự]/u', 'u', $t);
        $t = preg_replace('/[ýỳỷỹỵ]/u', 'y', $t);
        $t = str_replace('đ', 'd', $t);
        $t = preg_replace('/[^a-z0-9]+/', '-', $t);
        $slug = substr(trim($t, '-'), 0, 60);
        if (strlen($slug) < 3) $slug = 'bai-' . substr(md5($title . time()), 0, 6);
    }
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

    // Dựng lại toàn bộ dữ liệu đã nhập (kể cả dòng test nhập dở) để form không mất khi lỗi.
    $keepInput = function () use (&$editing, $action, $slug, $title, $topic, $difficulty, $suggestLang, $desc, $inDesc, $outDesc, $sampleIn, $sampleOut, $timeLimit, $testStdin, $testExpected, $testPublic, $testBoundary) {
        $rawCases = [];
        foreach ($testStdin as $i => $sin) {
            $rawCases[] = [
                'stdin_data' => (string)$sin,
                'expected_output' => (string)($testExpected[$i] ?? ''),
                'is_public' => isset($testPublic[$i]) ? 1 : 0,
                'is_boundary' => isset($testBoundary[$i]) ? 1 : 0,
            ];
        }
        if (!$rawCases) {
            $rawCases = [['stdin_data' => '', 'expected_output' => '', 'is_public' => 1, 'is_boundary' => 0]];
        }
        $editing = [
            'id' => $action === 'update' ? (int)($_POST['problem_id'] ?? 0) : 0,
            'slug' => $slug, 'title' => $title, 'topic' => $topic, 'difficulty' => $difficulty,
            'suggested_lang' => $suggestLang, 'description' => $desc, 'input_desc' => $inDesc,
            'output_desc' => $outDesc, 'sample_input' => $sampleIn, 'sample_output' => $sampleOut,
            'time_limit_ms' => $timeLimit, 'changelog' => trim($_POST['changelog'] ?? 'Cập nhật đề / test'),
            'cases' => $rawCases,
        ];
    };
    $errStep = 1; // submit lỗi thì mở lại đúng bước chứa lỗi

    if (!preg_match('/^[a-z0-9-]{3,100}$/', $slug)) {
        $err = 'Slug chưa đúng — chỉ gồm chữ thường, số, gạch ngang (3–100 ký tự). Sửa mỗi ô này là được, các ô khác vẫn giữ nguyên.';
        $errStep = 1;
        $keepInput();
    } elseif ($title === '' || $desc === '' || $sampleIn === '' || $sampleOut === '') {
        $err = 'Còn thiếu thông tin bắt buộc (đánh dấu *). Chỉ cần bổ sung ô trống, các ô đã nhập vẫn giữ nguyên.';
        $errStep = $title === '' ? 1 : 2;
        $keepInput();
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
            $err = 'Chưa có test case hợp lệ — chỉ cần điền đủ Input + Output ở 1 dòng, các thông tin trước đó vẫn giữ nguyên.';
            $errStep = 3;
            $keepInput();
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
                $err = $e->getMessage() . ' Các thông tin đã nhập vẫn giữ nguyên, chỉ cần sửa chỗ sai.';
                $errStep = str_contains($e->getMessage(), 'Slug') ? 1 : 3;
                $keepInput(); // giữ toàn bộ, kể cả dòng test nhập dở
            }
        }
    }
}

$pageTitle = $editing ? 'Sửa bài tập #' . (int)$editing['id'] : 'Thêm bài tập mới';
$activeNav = 'prob_new';
require FE_DIR . '/partials/layout_head.php';
$ecases = $editing['cases'] ?? [['stdin_data' => '', 'expected_output' => '', 'is_public' => 1, 'is_boundary' => 0]];
?>
<div style="max-width:760px; margin:28px auto; padding:0 24px;">
    <h2 style="margin-bottom:4px;"><?= $editing ? 'Sửa bài tập #' . (int)$editing['id'] : 'Tạo bài tập mới' ?></h2>
    <p style="color:var(--cl-muted); margin:0 0 8px;">Chỉ 3 bước — mất khoảng 2 phút.</p>
    <p style="margin-bottom:16px;"><a href="<?= url('/frontend/instructor/problems.php') ?>">← Về danh sách bài tập</a></p>

    <?php if ($err): ?><div style="background:#fee2e2; color:#b91c1c; padding:12px 18px; border-radius:8px; margin-bottom:16px; font-weight:600;">✘ <?= e($err) ?></div><?php endif; ?>
    <div id="pf-draft-note" hidden style="background:#fef9c3; color:#854d0e; padding:10px 16px; border-radius:8px; margin-bottom:12px; font-size:0.85rem;">Đã khôi phục bản nháp bạn đang nhập dở. <button type="button" id="pf-draft-clear" style="text-decoration:underline; background:none; border:none; cursor:pointer; color:inherit; font-size:inherit;">Xóa nháp</button></div>

    <!-- Thanh tiến trình -->
    <ol id="pf-steps" style="display:flex; gap:8px; list-style:none; padding:0; margin:0 0 16px;">
        <li data-step="1" style="flex:1; text-align:center; padding:8px; border-radius:8px; background:#dbeafe; font-weight:700; font-size:0.85rem;">1. Thông tin</li>
        <li data-step="2" style="flex:1; text-align:center; padding:8px; border-radius:8px; background:#f1f5f9; font-weight:600; font-size:0.85rem; color:#64748b;">2. Nội dung đề</li>
        <li data-step="3" style="flex:1; text-align:center; padding:8px; border-radius:8px; background:#f1f5f9; font-weight:600; font-size:0.85rem; color:#64748b;">3. Test chấm</li>
    </ol>

    <div style="background:#fff; padding:24px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
        <form method="POST" id="problem-form" novalidate>
            <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
            <?php if ($editing && ($editing['id'] ?? 0) > 0): ?><input type="hidden" name="problem_id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>

            <!-- BƯỚC 1 -->
            <section data-pane="1">
                <div class="form-group">
                    <label>Tiêu đề bài tập *</label>
                    <input type="text" name="title" id="pf-title" class="form-control" required placeholder="VD: Tính tổng hai số" value="<?= e($editing['title'] ?? '') ?>">
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div class="form-group"><label>Chủ đề</label>
                        <select name="topic" class="form-control">
                            <?php foreach (['Cơ bản', 'Rẽ nhánh', 'Vòng lặp', 'Số học', 'Mảng', 'Xử lý chuỗi', 'Tìm kiếm', 'Quy hoạch động'] as $t): ?>
                                <option <?= ($editing['topic'] ?? 'Cơ bản') === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Độ khó</label>
                        <select name="difficulty" class="form-control">
                            <?php foreach (['Easy' => 'Dễ', 'Medium' => 'Trung bình', 'Hard' => 'Khó'] as $v => $l): ?>
                                <option value="<?= $v ?>" <?= ($editing['difficulty'] ?? 'Easy') === $v ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <details style="margin-top:4px; font-size:0.85rem; color:#475569;">
                    <summary style="cursor:pointer;">Tùy chỉnh slug (đường dẫn)</summary>
                    <input type="text" name="slug" id="pf-slug" class="form-control" style="margin-top:8px;" value="<?= e($editing['slug'] ?? '') ?>" placeholder="tu-dong-tao-tu-tieu-de">
                    <p style="margin:4px 0 0;">Để trống sẽ tự tạo từ tiêu đề. Chỉ gồm chữ thường, số, gạch ngang.</p>
                </details>
            </section>

            <!-- BƯỚC 2 -->
            <section data-pane="2" hidden>
                <div class="form-group">
                    <label>Mô tả đề bài *</label>
                    <textarea name="description" class="form-control" rows="4" required placeholder="Cho hai số a, b. Tính tổng..."><?= e($editing['description'] ?? '') ?></textarea>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div class="form-group"><label>Mô tả đầu vào</label><textarea name="input_desc" class="form-control" rows="2" placeholder="Dòng 1: hai số a b"><?= e($editing['input_desc'] ?? '') ?></textarea></div>
                    <div class="form-group"><label>Mô tả đầu ra</label><textarea name="output_desc" class="form-control" rows="2" placeholder="Một số duy nhất"><?= e($editing['output_desc'] ?? '') ?></textarea></div>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div class="form-group"><label>Ví dụ input *</label><textarea name="sample_input" class="form-control" rows="2" required placeholder="2 3"><?= e($editing['sample_input'] ?? '') ?></textarea></div>
                    <div class="form-group"><label>Ví dụ output *</label><textarea name="sample_output" class="form-control" rows="2" required placeholder="5"><?= e($editing['sample_output'] ?? '') ?></textarea></div>
                </div>
            </section>

            <!-- BƯỚC 3 -->
            <section data-pane="3" hidden>
                <p style="font-size:0.88rem; color:#475569; margin-top:0;">Test case là dữ liệu dùng để chấm tự động. Ca <b>công khai</b> học viên được xem, ca còn lại dùng để chấm điểm.</p>
                <div id="case-list">
                    <?php foreach ($ecases as $i => $c): ?>
                    <div class="case-row" style="border:1px solid var(--cl-border); border-radius:8px; padding:10px; margin:8px 0;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <b style="font-size:0.85rem;">Test #<span class="case-no"><?= $i + 1 ?></span></b>
                            <button type="button" class="btn case-del" style="background:#fee2e2; padding:2px 10px; font-size:0.8rem;">Xóa</button>
                        </div>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                            <textarea name="test_stdin[]" class="form-control" rows="2" placeholder="Input (stdin)"><?= e($c['stdin_data']) ?></textarea>
                            <textarea name="test_expected[]" class="form-control" rows="2" placeholder="Output mong đợi"><?= e($c['expected_output']) ?></textarea>
                        </div>
                        <label style="font-size:0.82rem; margin-top:6px; display:inline-block;"><input type="checkbox" name="test_public[<?= $i ?>]" value="1" <?= $c['is_public'] ? 'checked' : '' ?>> Cho học viên xem (công khai)</label>
                        <input type="hidden" name="test_boundary[<?= $i ?>]" value="<?= $c['is_boundary'] ? 1 : 0 ?>">
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn" id="pf-add-case" style="background:#e2e8f0; margin-bottom:12px;" data-case-start="<?= count($ecases) ?>">+ Thêm test case</button>

                <details style="font-size:0.85rem; color:#475569; margin-bottom:12px;">
                    <summary style="cursor:pointer; font-weight:600;">Cài đặt nâng cao (không bắt buộc)</summary>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:8px;">
                        <div class="form-group"><label>Ngôn ngữ gợi ý</label>
                            <select name="suggested_lang" class="form-control">
                                <?php foreach (JudgeEngine::LANGUAGES as $key => $meta): ?>
                                    <option value="<?= e($key) ?>" <?= ($editing['suggested_lang'] ?? 'python') === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label>Giới hạn chạy (ms)</label><input type="number" name="time_limit_ms" class="form-control" value="<?= (int)($editing['time_limit_ms'] ?? 1000) ?>" min="100" step="100"></div>
                    </div>
                    <?php if ($editing && ($editing['id'] ?? 0) > 0): ?>
                    <div class="form-group"><label>Ghi chú thay đổi</label><input type="text" name="changelog" class="form-control" value="Cập nhật đề / test"></div>
                    <?php endif; ?>
                </details>
            </section>

            <div style="display:flex; gap:10px; margin-top:16px;">
                <button type="button" class="btn" id="pf-back" style="background:#e2e8f0;" hidden>← Quay lại</button>
                <button type="button" class="btn btn-primary" id="pf-next" style="flex:1; justify-content:center;">Tiếp tục →</button>
                <button type="submit" class="btn btn-primary" id="pf-submit" style="flex:1; justify-content:center;" hidden><?= $editing && ($editing['id'] ?? 0) > 0 ? 'Lưu bài tập' : 'Tạo bài tập' ?></button>
                <a class="btn" style="background:#f1f5f9;" href="<?= url('/frontend/instructor/problems.php') ?>">Hủy</a>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    let step = 1;
    const panes = document.querySelectorAll('#problem-form [data-pane]');
    const tabs = document.querySelectorAll('#pf-steps li');
    const btnNext = document.getElementById('pf-next');
    const btnBack = document.getElementById('pf-back');
    const btnSubmit = document.getElementById('pf-submit');
    const titleEl = document.getElementById('pf-title');
    const slugEl = document.getElementById('pf-slug');
    let slugTouched = <?= !empty($editing['slug']) ? 'true' : 'false' ?>;
    slugEl.addEventListener('input', () => slugTouched = true);
    titleEl.addEventListener('input', () => {
        if (slugTouched) return;
        slugEl.value = titleEl.value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/đ/g, 'd').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60);
    });

    function show(n) {
        step = n;
        panes.forEach(p => p.hidden = p.dataset.pane != n);
        tabs.forEach(t => {
            const active = +t.dataset.step === n, done = +t.dataset.step < n;
            t.style.background = active ? '#dbeafe' : done ? '#dcfce7' : '#f1f5f9';
            t.style.color = active ? '#1d4ed8' : done ? '#15803d' : '#64748b';
            t.style.fontWeight = active ? '700' : '600';
        });
        btnBack.hidden = n === 1;
        btnNext.hidden = n === 3;
        btnSubmit.hidden = n !== 3;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    function validPane(n, mark) {
        const pane = document.querySelector(`#problem-form [data-pane="${n}"]`);
        let ok = true;
        for (const el of pane.querySelectorAll('[required]')) {
            const empty = !el.value.trim();
            el.style.borderColor = empty ? '#ef4444' : '';
            if (empty && ok) {
                ok = false;
                if (mark) {
                    el.focus();
                    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
                    const label = el.closest('.form-group')?.querySelector('label')?.textContent?.trim() || 'Thông tin';
                    el.title = `Thiếu: ${label} — chỉ cần điền ô này, các ô khác vẫn giữ nguyên`;
                }
            }
        }
        return ok;
    }
    btnNext.onclick = () => { if (validPane(step, true)) show(Math.min(3, step + 1)); };
    btnBack.onclick = () => show(Math.max(1, step - 1));
    tabs.forEach(t => t.onclick = () => { if (+t.dataset.step < step || validPane(step)) show(+t.dataset.step); });

    // Test case: thêm / xóa / đánh số lại
    const list = document.getElementById('case-list');
    let idx = list.querySelectorAll('.case-row').length;
    function renumber() {
        list.querySelectorAll('.case-row').forEach((row, i) => {
            row.querySelector('.case-no').textContent = i + 1;
            row.querySelector('input[type=checkbox]').name = `test_public[${i}]`;
            row.querySelector('input[type=hidden]').name = `test_boundary[${i}]`;
        });
    }
    list.addEventListener('click', e => {
        if (e.target.classList.contains('case-del')) {
            if (list.querySelectorAll('.case-row').length <= 1) { alert('Cần ít nhất 1 test case.'); return; }
            e.target.closest('.case-row').remove();
            renumber();
        }
    });
    document.getElementById('pf-add-case').onclick = () => {
        const div = document.createElement('div');
        div.className = 'case-row';
        div.style.cssText = 'border:1px solid var(--cl-border); border-radius:8px; padding:10px; margin:8px 0;';
        div.innerHTML = `<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                <b style="font-size:0.85rem;">Test #<span class="case-no"></span></b>
                <button type="button" class="btn case-del" style="background:#fee2e2; padding:2px 10px; font-size:0.8rem;">Xóa</button></div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                <textarea name="test_stdin[]" class="form-control" rows="2" placeholder="Input (stdin)"></textarea>
                <textarea name="test_expected[]" class="form-control" rows="2" placeholder="Output mong đợi"></textarea></div>
            <label style="font-size:0.82rem; margin-top:6px; display:inline-block;">
                <input type="checkbox" name="test_public[${idx}]" value="1" checked> Cho học viên xem (công khai)</label>
            <input type="hidden" name="test_boundary[${idx}]" value="0">`;
        idx++;
        list.appendChild(div);
        renumber();
    };
    document.getElementById('problem-form').addEventListener('submit', e => {
        if (!validPane(1, true)) { e.preventDefault(); show(1); return; }
        if (!validPane(2, true)) { e.preventDefault(); show(2); return; }
        if (!slugEl.value.trim() && titleEl.value.trim()) {
            titleEl.dispatchEvent(new Event('input'));
        }
        if (slugEl.value.trim() && !/^[a-z0-9-]{3,100}$/.test(slugEl.value.trim())) {
            e.preventDefault(); show(1);
            slugEl.focus(); slugEl.style.borderColor = '#ef4444';
            alert('Slug chỉ gồm chữ thường, số, gạch ngang (3–100 ký tự). Chỉ cần sửa ô này.');
            return;
        }
        clearDraft(); // submit hợp lệ client-side thì xóa nháp
    });
    // Mở đúng bước chứa lỗi khi server trả về
    const errStep = <?= (int)($errStep ?? 1) ?>;
    const hasServerErr = <?= $err ? 'true' : 'false' ?>;
    // --- Tự lưu nháp vào localStorage (chống mất khi F5 / rớt mạng) ---
    const isEdit = <?= ($editing && ($editing['id'] ?? 0) > 0) ? 'true' : 'false' ?>;
    const draftKey = isEdit ? null : 'pf-draft-new';
    const form = document.getElementById('problem-form');
    const draftNote = document.getElementById('pf-draft-note');
    function clearDraft() { if (draftKey) try { localStorage.removeItem(draftKey); } catch (_) {} }
    function collectDraft() {
        const data = {};
        form.querySelectorAll('[name]').forEach(el => {
            if (el.type === 'checkbox') data[el.name] = el.checked;
            else if (!(el.name in data)) data[el.name] = el.value;
        });
        // test case là mảng — gom riêng theo thứ tự dòng
        data._cases = [...list.querySelectorAll('.case-row')].map(row => ({
            stdin: row.querySelector('textarea[name="test_stdin[]"]').value,
            exp: row.querySelector('textarea[name="test_expected[]"]').value,
            pub: row.querySelector('input[type=checkbox]').checked,
        }));
        return data;
    }
    function applyDraft(d) {
        if (!d) return false;
        let filled = false;
        form.querySelectorAll('[name]').forEach(el => {
            const v = d[el.name];
            if (v === undefined || el.name === 'test_stdin[]' || el.name === 'test_expected[]') return;
            if (el.type === 'checkbox') { if (v) { el.checked = true; filled = true; } }
            else if (v !== '' && !el.value) { el.value = v; filled = true; }
        });
        if (d._cases && d._cases.length) {
            const rows = list.querySelectorAll('.case-row');
            d._cases.forEach((c, i) => {
                let row = rows[i];
                if (!row) { document.getElementById('pf-add-case').click(); row = list.querySelectorAll('.case-row')[i]; }
                if (!row) return;
                const [ta1, ta2] = row.querySelectorAll('textarea');
                if (!ta1.value && c.stdin) { ta1.value = c.stdin; filled = true; }
                if (!ta2.value && c.exp) { ta2.value = c.exp; filled = true; }
                if (c.pub) row.querySelector('input[type=checkbox]').checked = true;
            });
        }
        return filled;
    }
    if (draftKey && !hasServerErr) {
        let saved = null;
        try { saved = JSON.parse(localStorage.getItem(draftKey)); } catch (_) {}
        const anyTyped = [...form.querySelectorAll('input[type=text], textarea')].some(el => el.value.trim());
        // Chỉ khôi phục nháp khi form đang trống (mở mới), tránh đè dữ liệu edit/server
        if (saved && !anyTyped && applyDraft(saved)) draftNote.hidden = false;
    }
    if (draftKey) {
        let t = null;
        form.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => {
            try { localStorage.setItem(draftKey, JSON.stringify(collectDraft())); } catch (_) {}
        }, 400); });
        document.getElementById('pf-draft-clear').onclick = () => { clearDraft(); draftNote.hidden = true; };
    }
    // Nhận dữ liệu từ trang AI (chế độ 1: xem trước trong form)
    const fromAI = new URLSearchParams(location.search).get('from_ai') === '1';
    if (fromAI && !hasServerErr) {
        let pre = null;
        try { pre = JSON.parse(sessionStorage.getItem('ai-prefill')); sessionStorage.removeItem('ai-prefill'); } catch (_) {}
        if (pre) {
            const set = (name, v) => { const el = form.querySelector(`[name="${name}"]`); if (el && v) el.value = v; };
            set('title', pre.title); set('description', pre.description);
            set('input_desc', pre.input_desc); set('output_desc', pre.output_desc);
            set('sample_input', pre.sample_input); set('sample_output', pre.sample_output);
            if (pre.topic) { const t = form.querySelector('[name="topic"]'); if (t) t.value = pre.topic; }
            if (pre.difficulty) { const d = form.querySelector('[name="difficulty"]'); if (d) d.value = pre.difficulty; }
            list.innerHTML = '';
            (pre.test_cases?.length ? pre.test_cases : [{ stdin: '', expected: '', is_public: true }]).forEach(c => {
                document.getElementById('pf-add-case').click();
                const row = list.querySelectorAll('.case-row')[list.querySelectorAll('.case-row').length - 1];
                const [ta1, ta2] = row.querySelectorAll('textarea');
                ta1.value = c.stdin || ''; ta2.value = c.expected || '';
                row.querySelector('input[type=checkbox]').checked = !!c.is_public;
            });
            renumber();
            clearDraft();
            bubble: {
                const note = document.getElementById('pf-draft-note');
                note.innerHTML = 'Đã điền dữ liệu từ AI — kiểm tra lại rồi bấm Tạo bài tập.';
                note.hidden = false;
            }
        }
    }
    show(hasServerErr ? errStep : 1);
})();
</script>

<?php
require FE_DIR . '/partials/layout_foot.php';
?>
