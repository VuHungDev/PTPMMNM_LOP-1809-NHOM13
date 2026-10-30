<?php
// frontend/instructor/ai_chat.php — Chatbot hỗ trợ tạo bài tập từ đề thô.
// Luồng: dán đề → AI bóc tách → 2 chế độ: (1) xem trước / sửa trong form, (2) đẩy lên luôn.
require_once __DIR__ . '/../../backend/lib/Auth.php';
require_once __DIR__ . '/../../backend/lib/AIHelper.php';
$user = Auth::requireRole(['teacher', 'admin']);

$pageTitle = 'AI tạo bài tập';
$activeNav = 'prob_ai';
require FE_DIR . '/partials/layout_head.php';
?>
<div style="max-width:900px; margin:28px auto; padding:0 24px;">
    <h2 style="margin-bottom:4px;">AI tạo bài tập</h2>
    <p style="color:var(--cl-muted); margin:0 0 16px;">
        Dán đề bài vào khung chat — AI sẽ bóc tách thành khung bài tập.
        <?= AIHelper::hasKey() ? 'AI đã sẵn sàng.' : 'Chưa có API key — đang dùng chế độ bóc tách tự động (vẫn đủ xem trước & đẩy bài). Thêm key vào <code>backend/config/ai.php</code> để AI thông minh hơn.' ?>
    </p>

    <div style="display:grid; grid-template-columns:1fr; gap:16px;">
        <!-- Khung chat -->
        <div style="background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.05); overflow:hidden;">
            <div id="ai-msgs" style="padding:18px; min-height:140px; max-height:340px; overflow-y:auto; font-size:0.92rem;">
                <div style="background:#f1f5f9; border-radius:10px; padding:10px 14px; margin-bottom:8px;">
                    Chào thầy/cô! Hãy <b>dán đề bài</b> vào ô bên dưới rồi bấm <b>Gửi</b> — tôi sẽ tách thành tiêu đề, mô tả, ví dụ mẫu và test case để thầy/cô duyệt.
                </div>
            </div>
            <div style="display:flex; gap:8px; padding:12px; border-top:1px solid var(--cl-border);">
                <textarea id="ai-input" class="form-control" rows="3" style="flex:1;" placeholder="Dán đề bài vào đây... (VD: Cho hai số a, b. Tính tổng...)"></textarea>
                <button class="btn btn-primary" id="ai-send" style="align-self:flex-end;">Gửi</button>
            </div>
        </div>

        <!-- Khung xem trước -->
        <div id="ai-preview-wrap" hidden style="background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.05); padding:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <h3 style="margin:0;">Khung bài tập AI đã tách <small id="ai-src" style="color:var(--cl-muted); font-weight:400;"></small></h3>
                <span id="ai-status" style="font-size:0.82rem; color:#64748b;"></span>
            </div>
            <div id="ai-note" hidden style="background:#fef9c3; color:#854d0e; padding:10px 14px; border-radius:8px; margin-bottom:12px; font-size:0.85rem;"></div>
            <form id="ai-form" onsubmit="return false;">
                <div class="form-group"><label>Tiêu đề *</label><input name="title" class="form-control" required></div>
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px;">
                    <div class="form-group"><label>Chủ đề</label>
                        <select name="topic" class="form-control">
                            <?php foreach (['Cơ bản', 'Rẽ nhánh', 'Vòng lặp', 'Số học', 'Mảng', 'Xử lý chuỗi', 'Tìm kiếm', 'Quy hoạch động'] as $t): ?>
                                <option><?= e($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Độ khó</label>
                        <select name="difficulty" class="form-control">
                            <option value="Easy">Dễ</option>
                            <option value="Medium">Trung bình</option>
                            <option value="Hard">Khó</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Slug (để trống = tự tạo)</label><input name="slug" class="form-control" placeholder="tu-dong-tao"></div>
                </div>
                <div class="form-group"><label>Mô tả đề *</label><textarea name="description" class="form-control" rows="4" required></textarea></div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div class="form-group"><label>Mô tả đầu vào</label><textarea name="input_desc" class="form-control" rows="2"></textarea></div>
                    <div class="form-group"><label>Mô tả đầu ra</label><textarea name="output_desc" class="form-control" rows="2"></textarea></div>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div class="form-group"><label>Ví dụ input *</label><textarea name="sample_input" class="form-control" rows="2" required></textarea></div>
                    <div class="form-group"><label>Ví dụ output *</label><textarea name="sample_output" class="form-control" rows="2" required></textarea></div>
                </div>
                <label style="font-weight:700; font-size:0.9rem;">Test case:</label>
                <div id="ai-cases"></div>
                <button type="button" class="btn" id="ai-add-case" style="background:#e2e8f0; margin:6px 0 12px;">+ Thêm test</button>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="button" class="btn" id="ai-to-form" style="background:#e2e8f0;">Chế độ 1: Xem trước trong form tạo</button>
                    <button type="button" class="btn btn-primary" id="ai-push">Chế độ 2: Đẩy lên luôn</button>
                </div>
                <p style="font-size:0.82rem; color:#64748b; margin:8px 0 0;">
                    Chế độ 1 mở form tạo bài với dữ liệu đã điền sẵn để sửa thêm trước khi lưu.
                    Chế độ 2 lưu thẳng thành bài mới (slug trùng sẽ tự thêm hậu tố).
                </p>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    const msgs = document.getElementById('ai-msgs');
    const input = document.getElementById('ai-input');
    const btnSend = document.getElementById('ai-send');
    const wrap = document.getElementById('ai-preview-wrap');
    const casesEl = document.getElementById('ai-cases');
    const form = document.getElementById('ai-form');
    const statusEl = document.getElementById('ai-status');

    function bubble(html, mine) {
        const d = document.createElement('div');
        d.style.cssText = 'border-radius:10px; padding:10px 14px; margin-bottom:8px; background:' + (mine ? '#dbeafe' : '#f1f5f9') + ';' + (mine ? 'margin-left:15%;' : 'margin-right:15%;');
        d.innerHTML = html;
        msgs.appendChild(d);
        msgs.scrollTop = msgs.scrollHeight;
    }
    function loading() {
        const d = document.createElement('div');
        d.id = 'ai-typing';
        d.style.cssText = 'color:#64748b; font-size:0.85rem; margin-bottom:8px;';
        d.textContent = 'AI đang đọc đề...';
        msgs.appendChild(d);
        msgs.scrollTop = msgs.scrollHeight;
    }
    function doneLoading() { document.getElementById('ai-typing')?.remove(); }

    function addCaseRow(c) {
        const div = document.createElement('div');
        div.className = 'case-row';
        div.style.cssText = 'border:1px solid var(--cl-border); border-radius:8px; padding:10px; margin:8px 0;';
        div.innerHTML = `<div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                <textarea class="form-control c-stdin" rows="2" placeholder="Input (stdin)"></textarea>
                <textarea class="form-control c-exp" rows="2" placeholder="Output mong đợi"></textarea></div>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:6px;">
                <label style="font-size:0.82rem;"><input type="checkbox" class="c-pub"> Công khai</label>
                <button type="button" class="btn c-del" style="background:#fee2e2; padding:2px 10px; font-size:0.8rem;">Xóa</button></div>`;
        div.querySelector('.c-stdin').value = c?.stdin || '';
        div.querySelector('.c-exp').value = c?.expected || '';
        div.querySelector('.c-pub').checked = !!c?.is_public;
        div.querySelector('.c-del').onclick = () => {
            if (casesEl.querySelectorAll('.case-row').length <= 1) { alert('Cần ít nhất 1 test case.'); return; }
            div.remove();
        };
        casesEl.appendChild(div);
    }
    document.getElementById('ai-add-case').onclick = () => addCaseRow({ is_public: false });
    document.getElementById('ai-input').addEventListener('keydown', e => {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) send();
    });

    async function send() {
        const text = input.value.trim();
        if (!text) { alert('Hãy dán đề bài vào trước.'); return; }
        bubble('Đã gửi đề (' + text.length + ' ký tự).', true);
        loading();
        btnSend.disabled = true;
        try {
            const res = await fetch('<?= url('/backend/api/ai_parse_problem.php') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ text }),
            });
            const j = await res.json();
            doneLoading();
            if (!j.ok) { bubble('Lỗi: ' + (j.error || 'không rõ'), false); return; }
            fillPreview(j.data, j.source);
            bubble('Đã tách xong đề — khung bài tập ở bên dưới, thầy/cô kiểm tra rồi chọn chế độ lưu nhé.', false);
        } catch (e) {
            doneLoading();
            bubble('Mất kết nối, hãy thử lại.', false);
        } finally {
            btnSend.disabled = false;
        }
    }
    btnSend.onclick = send;

    function fillPreview(d, source) {
        form.title.value = d.title || '';
        form.topic.value = d.topic || 'Cơ bản';
        form.difficulty.value = d.difficulty || 'Easy';
        form.slug.value = '';
        form.description.value = d.description || '';
        form.input_desc.value = d.input_desc || '';
        form.output_desc.value = d.output_desc || '';
        form.sample_input.value = d.sample_input || '';
        form.sample_output.value = d.sample_output || '';
        casesEl.innerHTML = '';
        (d.test_cases || []).forEach(addCaseRow);
        if (!casesEl.children.length) addCaseRow({ is_public: true });
        document.getElementById('ai-src').textContent = source === 'ai' ? '(bởi AI)' : '(bóc tách tự động — chưa có API key)';
        const note = document.getElementById('ai-note');
        if (d._note) { note.textContent = d._note; note.hidden = false; } else note.hidden = true;
        wrap.hidden = false;
        wrap.scrollIntoView({ behavior: 'smooth' });
    }

    function collect() {
        const fd = new FormData(form);
        const cases = [...casesEl.querySelectorAll('.case-row')].map(r => ({
            stdin: r.querySelector('.c-stdin').value,
            expected: r.querySelector('.c-exp').value,
            is_public: r.querySelector('.c-pub').checked,
        }));
        return {
            title: fd.get('title'), topic: fd.get('topic'), difficulty: fd.get('difficulty'),
            slug: (fd.get('slug') || '').trim(), description: fd.get('description'),
            input_desc: fd.get('input_desc'), output_desc: fd.get('output_desc'),
            sample_input: fd.get('sample_input'), sample_output: fd.get('sample_output'),
            test_cases: cases,
        };
    }

    // Chế độ 1: mở form tạo với dữ liệu điền sẵn (qua sessionStorage)
    document.getElementById('ai-to-form').onclick = () => {
        try { sessionStorage.setItem('ai-prefill', JSON.stringify(collect())); } catch (_) {}
        location.href = '<?= url('/frontend/instructor/problem_form.php?from_ai=1') ?>';
    };

    // Chế độ 2: đẩy thẳng lên server
    document.getElementById('ai-push').onclick = async () => {
        statusEl.textContent = 'Đang lưu...';
        try {
            const res = await fetch('<?= url('/backend/api/ai_create_problem.php') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(collect()),
            });
            const j = await res.json();
            if (!j.ok) { statusEl.textContent = ''; bubble('Chưa lưu được: ' + (j.error || 'không rõ') + ' — sửa ô thiếu rồi bấm lại nhé.', false); return; }
            statusEl.textContent = '';
            bubble(`Đã tạo bài #${j.problem_id} thành công! <a href="${j.edit_url}">Mở bài vừa tạo</a>`, false);
        } catch (e) {
            statusEl.textContent = '';
            bubble('Mất kết nối, hãy thử lại.', false);
        }
    };
})();
</script>

<?php require FE_DIR . '/partials/layout_foot.php'; ?>
