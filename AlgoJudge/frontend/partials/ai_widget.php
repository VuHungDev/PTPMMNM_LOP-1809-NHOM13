<?php
// frontend/partials/ai_widget.php — Bong bóng chat AI nổi góc dưới-phải, có mặt mọi trang.
// Nhóm 1: hỏi đáp + tra cứu số liệu (mọi vai trò).
// Nhóm 2+3 (teacher/admin): tạo bài từ đề, tạo tài khoản, thêm test, bật/tắt bài, chấm lại, thử lại, xuất báo cáo — GHI luôn hỏi xác nhận.
?>
<style>
#ai-fab { position:fixed; right:22px; bottom:22px; z-index:9999; width:58px; height:58px; border-radius:50%;
  background:linear-gradient(135deg,#4f46e5,#7c7aed); color:#fff; border:none; cursor:pointer;
  font-size:26px; box-shadow:0 6px 20px rgba(79,70,229,.45); transition:transform .15s; }
#ai-fab:hover { transform:scale(1.07); }
#ai-panel { position:fixed; right:22px; bottom:92px; z-index:9999; width:min(420px,calc(100vw - 32px));
  max-height:min(640px,calc(100vh - 120px)); display:flex; flex-direction:column;
  background:#fff; border-radius:14px; box-shadow:0 12px 44px rgba(0,0,0,.22); overflow:hidden; }
#ai-panel[hidden] { display:none; }
#ai-panel-head { background:linear-gradient(135deg,#4f46e5,#7c3aed); color:#fff; padding:12px 16px;
  display:flex; justify-content:space-between; align-items:center; }
#ai-panel-head b { font-size:0.95rem; }
#ai-panel-head small { opacity:.85; font-size:0.75rem; display:block; }
#ai-panel-x { background:rgba(255,255,255,.2); border:none; color:#fff; border-radius:8px;
  width:28px; height:28px; cursor:pointer; font-size:15px; }
#ai-msgs { padding:12px 14px; overflow-y:auto; min-height:140px; max-height:320px; font-size:0.87rem; }
#ai-msgs a { color:#4f46e5; }
#ai-input-row { display:flex; align-items:flex-end; gap:6px; padding:10px 12px; border-top:1px solid #e2e8f0;
  background:#f8fafc; }
#ai-input-wrap { flex:1; display:flex; align-items:center; background:#fff; border:1px solid #cbd5e1;
  border-radius:22px; padding:4px 6px 4px 14px; transition:border-color .15s, box-shadow .15s; }
#ai-input-wrap:focus-within { border-color:#4f46e5; box-shadow:0 0 0 3px rgba(79,70,229,.12); }
#ai-input { flex:1; border:none; outline:none; background:transparent; padding:7px 0; font-size:0.85rem;
  resize:none; max-height:110px; line-height:1.35; font-family:inherit; }
#ai-send { flex:0 0 auto; width:34px; height:34px; min-width:0; padding:0; border-radius:50%;
  display:flex; align-items:center; justify-content:center; font-size:0; }
#ai-send svg { width:16px; height:16px; }
#ai-send:disabled { opacity:.45; }
#ai-preview { border-top:1px solid #e2e8f0; padding:12px 14px; overflow-y:auto; max-height:300px; font-size:0.85rem; }
#ai-preview[hidden] { display:none; }
#ai-preview input, #ai-preview textarea, #ai-preview select { width:100%; border:1px solid #cbd5e1;
  border-radius:6px; padding:6px 8px; font-size:0.83rem; margin:2px 0 8px; box-sizing:border-box; }
#ai-preview label { font-weight:600; font-size:0.78rem; color:#475569; }
.ai-case { border:1px solid #e2e8f0; border-radius:8px; padding:8px; margin:6px 0; }
.ai-btn { border:none; border-radius:8px; padding:9px 14px; cursor:pointer; font-weight:600; font-size:0.85rem; }
.ai-btn-p { background:#4f46e5; color:#fff; flex:1; }
.ai-btn-g { background:#e2e8f0; color:#1e293b; }
.ai-btn:disabled { opacity:.6; cursor:wait; }
.ai-md b, .ai-md strong { font-weight:700; }
</style>

<button id="ai-fab" title="Trợ lý AI" aria-label="Mở trợ lý AI">✨</button>

<div id="ai-panel" hidden role="dialog" aria-label="Trợ lý AI">
    <div id="ai-panel-head">
        <div><b>Trợ lý AI</b><small id="ai-sub">Hỏi đáp • tra cứu • tạo bài • thao tác hộ</small></div>
        <button id="ai-panel-x" title="Đóng">✕</button>
    </div>
    <div id="ai-msgs"></div>
    <div id="ai-preview" hidden></div>
    <div id="ai-input-row">
        <div id="ai-input-wrap">
            <textarea id="ai-input" rows="1" placeholder="Hỏi gì đó, hoặc dán đề bài..."></textarea>
        </div>
        <button class="ai-btn ai-btn-p" id="ai-send" title="Gửi (Enter)" aria-label="Gửi">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"></line><polyline points="5 12 12 5 19 12"></polyline></svg>
        </button>
    </div>
</div>

<script>
(function () {
    if (document.getElementById('ai-fab').dataset.done) return;
    document.getElementById('ai-fab').dataset.done = '1';
    const fab = document.getElementById('ai-fab');
    const panel = document.getElementById('ai-panel');
    const msgs = document.getElementById('ai-msgs');
    const input = document.getElementById('ai-input');
    const btnSend = document.getElementById('ai-send');
    const preview = document.getElementById('ai-preview');
    const base = '<?= url('') ?>';
    const isStaff = <?= in_array($user['role'] ?? '', ['teacher', 'admin'], true) ? 'true' : 'false' ?>;
    const HIST_KEY = 'ai-chat-history';

    function loadHistory() {
        try { return JSON.parse(sessionStorage.getItem(HIST_KEY) || '[]'); } catch (_) { return []; }
    }
    function saveHistory(list) {
        try { sessionStorage.setItem(HIST_KEY, JSON.stringify(list.slice(-60))); } catch (_) {}
    }
    const history = loadHistory();

    function greeting() {
        return `<div style="background:#f1f5f9; border-radius:10px; padding:9px 12px; margin-bottom:8px;">`
            + (isStaff
                ? `Chào thầy/cô! Tôi có thể:<br>• Trả lời cách dùng, tra cứu số liệu lớp<br>• <b>Dán đề</b> → tôi tạo khung bài tập<br>• Tạo tài khoản, thêm test, chấm lại... <b>(luôn hỏi xác nhận trước)</b>`
                : `Chào bạn! Tôi có thể giải thích cách luyện tập, nộp bài, xem điểm, gợi ý hướng làm bài (không đưa code giải).`)
            + `</div>`;
    }

    if (history.length) {
        msgs.innerHTML = '';
        history.forEach(m => bubble(m.text, m.mine, false));
    } else {
        msgs.innerHTML = greeting();
    }

    fab.onclick = () => { panel.hidden = !panel.hidden; if (!panel.hidden) input.focus(); };
    document.getElementById('ai-panel-x').onclick = () => panel.hidden = true;

    function md(s) {
        let h = (s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        h = h.replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/`(.+?)`/g, '<code>$1</code>');
        h = h.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank">$1</a>');
        return h.replace(/\n/g, '<br>');
    }
    function bubble(html, mine, record = true) {
        const d = document.createElement('div');
        d.className = mine ? '' : 'ai-md';
        d.style.cssText = 'border-radius:10px; padding:9px 12px; margin-bottom:8px; background:' + (mine ? '#dbeafe' : '#f1f5f9') + ';' + (mine ? 'margin-left:12%;' : 'margin-right:12%;');
        d.innerHTML = html;
        msgs.appendChild(d);
        msgs.scrollTop = msgs.scrollHeight;
        if (record) {
            history.push({ text: html, mine, raw: true });
            saveHistory(history);
        }
    }

    async function send() {
        const text = input.value.trim();
        if (!text) return;
        bubble(md(text), true);
        input.value = '';
        input.style.height = 'auto';
        preview.hidden = true;
        const typing = document.createElement('div');
        typing.style.cssText = 'color:#64748b; font-size:0.8rem; margin-bottom:8px;';
        typing.textContent = 'AI đang trả lời...';
        msgs.appendChild(typing);
        btnSend.disabled = true;
        try {
            const res = await fetch(base + '/backend/api/ai_chat.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ text }),
            });
            const j = await res.json();
            typing.remove();
            if (!j.ok) { bubble('Lỗi: ' + md(j.error || 'không rõ'), false); return; }
            if (j.kind === 'parse_problem') { renderPreview(j.data, j.source); bubble('Đã tách xong đề — kiểm tra khung bên dưới rồi chọn <b>Xem trước</b> hoặc <b>Thêm bài</b> nhé.', false); }
            else if (j.kind === 'confirm') { renderConfirm(j); }
            else { bubble(md(j.answer), false); }
        } catch (e) {
            typing.remove();
            bubble('Mất kết nối, hãy thử lại.', false);
        } finally {
            btnSend.disabled = false;
        }
    }
    btnSend.onclick = send;
    input.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });
    input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 110) + 'px';
    });

    // Thẻ xác nhận cho thao tác GHI: Đồng ý → gọi execute, Hủy → bỏ
    function renderConfirm(j) {
        bubble(`Tôi sắp làm việc này:<br>• ${md(j.summary)}<br><br>`
            + `<button class="ai-btn ai-btn-p" data-ok="1" style="padding:7px 16px;">Đồng ý</button> `
            + `<button class="ai-btn ai-btn-g" data-no="1" style="padding:7px 16px;">Hủy</button>`, false, false);
        const box = msgs.lastChild;
        box.querySelector('[data-ok]').onclick = async (ev) => {
            const btn = ev.currentTarget;
            btn.disabled = true;
            try {
                const res = await fetch(base + '/backend/api/ai_chat.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ confirm_token: j.token }),
                });
                const r = await res.json();
                bubble(r.ok ? md(r.answer) : 'Lỗi: ' + md(r.error || 'không rõ'), false);
            } catch (e) {
                bubble('Mất kết nối, hãy thử lại.', false);
            }
            box.querySelectorAll('button').forEach(b => b.disabled = true);
        };
        box.querySelector('[data-no]').onclick = (ev) => {
            bubble('Đã hủy — không có gì thay đổi.', false);
            box.querySelectorAll('button').forEach(b => b.disabled = true);
        };
    }

    function caseRow(c) {
        const div = document.createElement('div');
        div.className = 'ai-case';
        div.innerHTML = `<textarea rows="2" class="c-in" placeholder="Input (stdin)"></textarea>
            <textarea rows="2" class="c-out" placeholder="Output mong đợi"></textarea>
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <label style="font-weight:400;"><input type="checkbox" class="c-pub"> Công khai</label>
                <button type="button" class="ai-btn ai-btn-g c-del" style="padding:3px 10px; font-size:0.75rem;">Xóa</button></div>`;
        div.querySelector('.c-in').value = c?.stdin || '';
        div.querySelector('.c-out').value = c?.expected || '';
        div.querySelector('.c-pub').checked = !!c?.is_public;
        div.querySelector('.c-del').onclick = () => {
            if (preview.querySelectorAll('.ai-case').length <= 1) { alert('Cần ít nhất 1 test case.'); return; }
            div.remove();
        };
        return div;
    }
    function esc(s) { return (s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

    function renderPreview(d, source) {
        preview.innerHTML = `
            <div style="font-weight:700; margin-bottom:6px;">Khung bài tập ${source === 'ai' ? '(bởi AI ✨)' : '(tự động — kiểm tra lại giúp tôi)'}</div>
            ${d._note ? `<div style="background:#fef9c3; color:#854d0e; padding:8px 10px; border-radius:8px; margin-bottom:8px;">${esc(d._note)}</div>` : ''}
            <label>Tiêu đề *</label><input id="ap-title" value="${esc(d.title)}">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                <div><label>Chủ đề</label><select id="ap-topic">${['Cơ bản','Rẽ nhánh','Vòng lặp','Số học','Mảng','Xử lý chuỗi','Tìm kiếm','Quy hoạch động'].map(t => `<option ${t === d.topic ? 'selected' : ''}>${t}</option>`).join('')}</select></div>
                <div><label>Độ khó</label><select id="ap-diff">${[['Easy','Dễ'],['Medium','Trung bình'],['Hard','Khó']].map(([v,l]) => `<option value="${v}" ${v === d.difficulty ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
            </div>
            <label>Mô tả đề *</label><textarea id="ap-desc" rows="3"></textarea>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                <div><label>VD input *</label><textarea id="ap-sin" rows="2"></textarea></div>
                <div><label>VD output *</label><textarea id="ap-sout" rows="2"></textarea></div>
            </div>
            <label>Test case</label><div id="ap-cases"></div>
            <button type="button" class="ai-btn ai-btn-g" id="ap-add" style="font-size:0.78rem; padding:5px 10px; margin-bottom:8px;">+ Thêm test</button>
            <div style="display:flex; gap:8px;">
                <button type="button" class="ai-btn ai-btn-g" id="ap-review">Xem trước</button>
                <button type="button" class="ai-btn ai-btn-p" id="ap-push">Thêm bài</button>
            </div>`;
        preview.querySelector('#ap-desc').value = d.description || '';
        preview.querySelector('#ap-sin').value = d.sample_input || '';
        preview.querySelector('#ap-sout').value = d.sample_output || '';
        const box = preview.querySelector('#ap-cases');
        (d.test_cases?.length ? d.test_cases : [{ stdin: '', expected: '', is_public: true }]).forEach(c => box.appendChild(caseRow(c)));
        preview.querySelector('#ap-add').onclick = () => box.appendChild(caseRow({ is_public: false }));
        preview.hidden = false;

        const collect = () => ({
            title: preview.querySelector('#ap-title').value,
            topic: preview.querySelector('#ap-topic').value,
            difficulty: preview.querySelector('#ap-diff').value,
            slug: '', description: preview.querySelector('#ap-desc').value,
            input_desc: d.input_desc || '', output_desc: d.output_desc || '',
            sample_input: preview.querySelector('#ap-sin').value,
            sample_output: preview.querySelector('#ap-sout').value,
            test_cases: [...box.querySelectorAll('.ai-case')].map(r => ({
                stdin: r.querySelector('.c-in').value, expected: r.querySelector('.c-out').value,
                is_public: r.querySelector('.c-pub').checked,
            })),
        });
        preview.querySelector('#ap-review').onclick = () => {
            try { sessionStorage.setItem('ai-prefill', JSON.stringify(collect())); } catch (_) {}
            location.href = base + '/frontend/instructor/problem_form.php?from_ai=1';
        };
        preview.querySelector('#ap-push').onclick = async (ev) => {
            const btn = ev.currentTarget;
            btn.disabled = true;
            try {
                const res = await fetch(base + '/backend/api/ai_create_problem.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(collect()),
                });
                const j = await res.json();
                if (!j.ok) { bubble('Chưa lưu được: ' + esc(j.error || 'không rõ') + ' — sửa ô thiếu rồi bấm lại nhé.', false); return; }
                bubble(`Đã tạo bài #${j.problem_id} thành công! <a href="${j.edit_url}">Mở bài vừa tạo</a>`, false);
                preview.hidden = true;
            } catch (e) {
                bubble('Mất kết nối, hãy thử lại.', false);
            } finally {
                btn.disabled = false;
            }
        };
    }
})();
</script>
