// frontend/assets/js/pages/playground.js — ES module Alpine-agnostic cho Sân chơi tự do.
// Dùng chung http.js / poll.js / editor.js; không còn JS inline trong playground.php.
import { apiFetch } from '../modules/http.js?v=20261115-run-3';
import { pollUntilDone } from '../modules/poll.js';
import { createCodeEditor, setEditorLanguage } from '../modules/editor.js';
import { ready as alpineReady } from '../modules/alpine-boot.js';

const LANG_META = window.PG_LANG_META || {};
const SAMPLES = window.PG_SAMPLES || {};

const state = {
    editor: null,
    editorFailed: false,
    drafts: {},
    lang: 'python',
    decorations: [],
    running: false,
    presentationGeneration: 0,
    booted: false,
};

function el(id) {
    return document.getElementById(id);
}

function badge(text, bg) {
    const b = el('pg-badge');
    b.innerText = text;
    if (bg) b.style.background = bg;
}

function clearErrorMark() {
    if (state.editor) state.decorations = state.editor.deltaDecorations(state.decorations, []);
}

function markErrorLine(line) {
    clearErrorMark();
    if (!state.editor || !line || line < 1 || !window.monaco) return;
    state.decorations = state.editor.deltaDecorations([], [{
        range: new window.monaco.Range(line, 1, line, 1),
        options: { isWholeLine: true, className: 'pg-error-line', glyphMarginClassName: 'pg-error-glyph' },
    }]);
    state.editor.revealLineInCenter(line);
}

function switchTab(name, btn) {
    document.querySelectorAll('.ide-left .ide-tab').forEach((t) => t.classList.remove('active'));
    btn.classList.add('active');
    el('pg-tab-stdin').style.display = name === 'stdin' ? '' : 'none';
    el('pg-tab-libs').style.display = name === 'libs' ? '' : 'none';
}

function changeLang() {
    // Reset presentation only; keep the in-flight run locked until it really settles.
    clearResult();
    if (state.editor) state.drafts[state.lang] = state.editor.getValue();
    state.lang = el('pg-lang').value;
    try {
        localStorage.setItem('aj.playground.lang', state.lang);
    } catch (e) { /* bỏ qua */ }
    if (state.editor) {
        state.editor.setValue(state.drafts[state.lang] ?? SAMPLES[state.lang] ?? '');
        setEditorLanguage(state.editor, LANG_META[state.lang]);
        clearErrorMark();
    }
}

function clearResult() {
    // Clear affects presentation only; the server-side run can still finish safely.
    state.presentationGeneration++;
    el('pg-stdout').innerText = 'Bấm "Chạy" để thực thi.';
    el('pg-stderr').innerText = 'Chưa có lỗi.';
    badge('Chờ lệnh', '');
    el('pg-time').innerText = '';
    clearErrorMark();
}

function showEditorUnavailable() {
    badge(state.editorFailed ? 'LỖI TẢI BỘ SOẠN THẢO' : 'ĐANG TẢI BỘ SOẠN THẢO', '');
    el('pg-stdout').innerText = state.editorFailed
        ? 'Không tải được bộ soạn thảo. Kiểm tra kết nối mạng tới CDN Monaco rồi tải lại trang.'
        : 'Bộ soạn thảo đang tải, vui lòng chờ rồi bấm Chạy.';
}

async function run() {
    if (state.running) return;
    if (!state.editor) {
        showEditorUnavailable();
        return;
    }
    const presentationGeneration = ++state.presentationGeneration;
    const submitted = { language: state.lang, source: state.editor.getValue() };
    const canPresent = () => state.presentationGeneration === presentationGeneration;
    state.running = true;
    const btn = el('pg-run-btn');
    btn.disabled = true;
    let settled = false;
    const finish = () => {
        if (settled) return false;
        settled = true;
        state.running = false;
        btn.disabled = false;
        return true;
    };
    badge('ĐANG CHẠY...', '');
    el('pg-stdout').innerText = 'Đang thực thi...';
    el('pg-stderr').innerText = '...';
    el('pg-time').innerText = '';
    clearErrorMark();

    let customRunId = null;
    try {
        const { data } = await apiFetch('/run_custom.php', {
            method: 'POST',
            timeoutMs: 90000,
            body: { language: submitted.language, source_code: submitted.source, stdin: el('pg-stdin').value },
        });
        if (!data.ok) {
            if (canPresent()) {
                badge('LỖI', '#7c2d12');
                el('pg-stdout').innerText = 'Lỗi: ' + (data.error || 'Không tạo được phiên chạy.');
            }
            return;
        }
        customRunId = data.custom_run_id;
    } catch (err) {
        if (!canPresent()) return;
        if (err.name === 'TimeoutError') {
            badge('HẾT GIỜ CHỜ', '#92400e');
            el('pg-stdout').innerText = 'Chưa nhận được phản hồi sau 90 giây. Chưa biết kết quả; phiên chạy có thể vẫn đang xử lý trên máy chủ. Mã nguồn và dữ liệu nhập vẫn được giữ nguyên. Không tự động gửi lại.';
        } else {
            badge('MẤT KẾT NỐI', '#7c2d12');
            el('pg-stdout').innerText = 'Không gửi được mã nguồn. Kiểm tra mạng rồi thử lại.';
        }
        return;
    } finally {
        if (!customRunId) finish();
    }
    if (!customRunId) return;

    pollUntilDone({
        fetchOnce: async () => {
            const { res, data } = await apiFetch('/run_custom.php?id=' + customRunId);
            if (res.status === 401) throw new Error('auth');
            return data.ok ? data.data : null;
        },
        isDone: (d) => d && d.status === 'DONE',
        onDone: (d) => {
            if (!finish() || !canPresent()) return;
            render(d, submitted);
        },
        onTimeout: (msg) => {
            if (!finish() || !canPresent()) return;
            badge('HẾT GIỜ CHỜ', '#92400e');
            el('pg-stdout').innerText = msg;
        },
        intervalMs: 500,
        maxPolls: 180,
        timeoutText: 'Chưa nhận được kết quả sau 90 giây. Phiên chạy có thể vẫn đang xử lý.',
    });
}

function render(d, submitted) {
    const canMarkSource = state.editor && state.lang === submitted.language
        && state.editor.getValue() === submitted.source;
    el('pg-time').innerText = (d.execution_time_ms || 0) + ' ms';
    const outEl = el('pg-stdout');
    const errEl = el('pg-stderr');

    if (d.verdict === 'OK') {
        badge('✔ CHẠY XONG', '#065f46');
        outEl.innerText = d.stdout_output || '(chương trình không in gì ra stdout)';
        errEl.innerText = d.stderr_output ? 'Cảnh báo:\n' + d.stderr_output : 'Không có lỗi. ✔';
        errEl.style.color = '';
    } else if (d.verdict === 'CE') {
        badge('✘ LỖI BIÊN DỊCH (dòng ' + (d.error_line || '?') + ')', '#7c2d12');
        outEl.innerText = '(chưa chạy được do lỗi biên dịch)';
        errEl.style.color = '#f9a8d4';
        errEl.innerText = '⛔ Dòng ' + (d.error_line || '?') + ': ' + (d.error_message || d.stderr_output || '')
            + (d.error_hint ? '\n💡 Gợi ý: ' + d.error_hint : '')
            + (d.stderr_output ? '\n\n— Chi tiết đầy đủ —\n' + d.stderr_output : '');
        if (canMarkSource) markErrorLine(d.error_line);
    } else if (d.verdict === 'RE') {
        badge('✘ LỖI KHI CHẠY (dòng ' + (d.error_line || '?') + ')', '#7c2d12');
        outEl.innerText = d.stdout_output ? 'Đã in được trước khi lỗi:\n' + d.stdout_output : '(chưa in gì trước khi lỗi)';
        errEl.style.color = '#f9a8d4';
        errEl.innerText = '⛔ Dòng ' + (d.error_line || '?') + ': ' + (d.error_message || d.stderr_output || '')
            + (d.error_hint ? '\n💡 Gợi ý: ' + d.error_hint : '')
            + (d.stderr_output ? '\n\n— Chi tiết đầy đủ —\n' + d.stderr_output : '');
        if (canMarkSource) markErrorLine(d.error_line);
    } else if (d.verdict === 'TLE') {
        badge('⏱ QUÁ THỜI GIAN (10s)', '#92400e');
        outEl.innerText = d.stdout_output || '(chưa in gì)';
        errEl.style.color = '';
        errEl.innerText = '💡 Gợi ý: chương trình chạy quá 10 giây — kiểm tra vòng lặp vô hạn hoặc input() đang chờ nhập mà ô stdin còn thiếu dòng.';
    } else {
        badge('⚠ LỖI HỆ THỐNG', '#9d174d');
        outEl.innerText = '(máy chấm gặp sự cố, không phải lỗi code của bạn)';
        errEl.style.color = '';
        errEl.innerText = (d.stderr_output || 'Lỗi hệ thống.') + '\n💡 Gợi ý: hãy thử bấm Chạy lại sau ít phút.';
    }
}

// Alpine component: x-data="playground". Đăng ký qua Alpine.data trong alpine:init
// để không phụ thuộc thứ tự tải giữa Alpine (defer) và ES module.
function playgroundComponent() {
    return {
        init() {
            // Guard ở module scope (state.editor): mỗi lần Alpine initTree tạo
            // closure mới nên biến trong closure không giữ được trạng thái.
            // Không guard sẽ tạo 2 editor Monaco chồng nhau (nội dung lặp đôi).
            if (state.editor || state.booted) return;
            state.booted = true;
            try {
                state.lang = localStorage.getItem('aj.playground.lang') || 'python';
            } catch (e) { /* bỏ qua */ }
            if (!SAMPLES[state.lang]) state.lang = 'python';
            el('pg-lang').value = state.lang;
            const initialLang = state.lang;
            createCodeEditor('pg-editor', { value: SAMPLES[initialLang], language: LANG_META[initialLang] || 'python' })
                .then((ed) => {
                    state.editor = ed;
                    if (state.lang !== initialLang) {
                        setEditorLanguage(ed, LANG_META[state.lang]);
                        ed.setValue(state.drafts[state.lang] ?? SAMPLES[state.lang] ?? '');
                    }
                })
                .catch(() => {
                    state.editorFailed = true;
                    showEditorUnavailable();
                });
            document.addEventListener('keydown', (ev) => {
                if ((ev.ctrlKey || ev.metaKey) && ev.key === 'Enter') {
                    const ae = document.activeElement;
                    if (ae && (ae.tagName === 'TEXTAREA' || ae.tagName === 'INPUT')) return;
                    ev.preventDefault();
                    run();
                }
            });
        },
        changeLang() { changeLang(); },
        clear() { clearResult(); },
        run() { run(); },
    };
}

if (window.Alpine) {
    window.Alpine.data('playground', playgroundComponent);
} else {
    document.addEventListener('alpine:init', () => window.Alpine.data('playground', playgroundComponent));
}
window.playground = playgroundComponent;
alpineReady();

// Giữ các hàm global cho onclick cũ (phòng trang cache gọi trực tiếp).
window.pgTab = switchTab;
window.pgChangeLang = changeLang;
window.pgClear = clearResult;
window.pgRun = run;
