// frontend/assets/js/pages/playground.js — ES module Alpine-agnostic cho Sân chơi tự do.
// Dùng chung http.js / poll.js / editor.js; không còn JS inline trong playground.php.
import { apiFetch } from '../modules/http.js';
import { pollUntilDone } from '../modules/poll.js';
import { createCodeEditor, setEditorLanguage } from '../modules/editor.js';
import { ready as alpineReady } from '../modules/alpine-boot.js';

const LANG_META = window.PG_LANG_META || {};
const SAMPLES = window.PG_SAMPLES || {};

const state = {
    editor: null,
    lang: 'python',
    decorations: [],
    running: false,
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
    state.lang = el('pg-lang').value;
    try {
        localStorage.setItem('aj.playground.lang', state.lang);
    } catch (e) { /* bỏ qua */ }
    if (state.editor) {
        state.editor.setValue(SAMPLES[state.lang] || '');
        setEditorLanguage(state.editor, LANG_META[state.lang]);
        clearErrorMark();
    }
}

function clearResult() {
    el('pg-stdout').innerText = 'Bấm "Chạy" để thực thi.';
    el('pg-stderr').innerText = 'Chưa có lỗi.';
    badge('Chờ lệnh', '');
    el('pg-time').innerText = '';
    clearErrorMark();
}

async function run() {
    if (state.running) return;
    state.running = true;
    const btn = el('pg-run-btn');
    btn.disabled = true;
    badge('ĐANG CHẠY...', '');
    el('pg-stdout').innerText = 'Đang thực thi...';
    el('pg-stderr').innerText = '...';
    el('pg-time').innerText = '';
    clearErrorMark();

    let customRunId = null;
    try {
        const { data } = await apiFetch('/run_custom.php', {
            method: 'POST',
            body: { language: state.lang, source_code: state.editor ? state.editor.getValue() : '', stdin: el('pg-stdin').value },
        });
        if (!data.ok) {
            badge('LỖI', '#7c2d12');
            el('pg-stdout').innerText = 'Lỗi: ' + (data.error || 'Không tạo được phiên chạy.');
            return;
        }
        customRunId = data.custom_run_id;
    } catch (err) {
        badge('MẤT KẾT NỐI', '#7c2d12');
        el('pg-stdout').innerText = 'Không gửi được mã nguồn. Kiểm tra mạng rồi thử lại.';
        return;
    } finally {
        if (!customRunId) {
            state.running = false;
            btn.disabled = false;
        }
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
            state.running = false;
            btn.disabled = false;
            render(d);
        },
        onTimeout: (msg) => {
            state.running = false;
            btn.disabled = false;
            badge('HẾT GIỜ CHỜ', '#92400e');
            el('pg-stdout').innerText = msg;
        },
        intervalMs: 500,
        maxPolls: 40,
        timeoutText: 'Máy chấm không phản hồi sau 20 giây, hãy thử lại.',
    });
}

function render(d) {
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
        markErrorLine(d.error_line);
    } else if (d.verdict === 'RE') {
        badge('✘ LỖI KHI CHẠY (dòng ' + (d.error_line || '?') + ')', '#7c2d12');
        outEl.innerText = d.stdout_output ? 'Đã in được trước khi lỗi:\n' + d.stdout_output : '(chưa in gì trước khi lỗi)';
        errEl.style.color = '#f9a8d4';
        errEl.innerText = '⛔ Dòng ' + (d.error_line || '?') + ': ' + (d.error_message || d.stderr_output || '')
            + (d.error_hint ? '\n💡 Gợi ý: ' + d.error_hint : '')
            + (d.stderr_output ? '\n\n— Chi tiết đầy đủ —\n' + d.stderr_output : '');
        markErrorLine(d.error_line);
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
            createCodeEditor('pg-editor', { value: SAMPLES[state.lang], language: LANG_META[state.lang] || 'python' })
                .then((ed) => { state.editor = ed; })
                .catch(() => {
                    el('pg-stdout').innerText = 'Không tải được bộ soạn thảo (cần mạng để tải Monaco từ CDN).';
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
