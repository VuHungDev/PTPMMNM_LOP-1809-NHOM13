// frontend/assets/js/pages/workspace.js — ES module cho trang làm bài (workspace.php).
// Dùng chung http.js / poll.js / editor.js; cấu hình注入 qua window.WS_CFG từ PHP.
import { apiFetch } from '../modules/http.js';
import { pollUntilDone, formatCustomResult } from '../modules/poll.js';
import { createCodeEditor, setEditorLanguage } from '../modules/editor.js';
import { ready as alpineReady } from '../modules/alpine-boot.js';

const CFG = window.WS_CFG || {};
const LANG_META = CFG.langMeta || {};
const DEFAULTS = CFG.defaults || {};
const DRAFTS = Object.assign({}, DEFAULTS, CFG.drafts || {});
let editor = null;
let currentLang = CFG.suggestLang || 'python';
let lastIdempotencyKey = 'req_' + Date.now() + '_' + Math.random().toString(36).substring(2, 8);
let dirtySinceSave = false;

function el(id) {
    return document.getElementById(id);
}

let workspaceBooted = false; // module scope: closure component tạo mới mỗi initTree

export async function initWorkspace() {
    // Guard: Alpine có thể init component 2 lần (initTree bù + lần quét dự phòng).
    // Không guard sẽ tạo 2 editor Monaco chồng nhau trong cùng container (nội dung lặp đôi).
    // Biến cờ phải ở module scope vì mỗi initTree tạo closure component mới.
    if (workspaceBooted || editor) return;
    workspaceBooted = true;
    el('lang-select').value = currentLang;
    el('idem-display').innerText = lastIdempotencyKey;
    try {
        editor = await createCodeEditor('monaco-container', {
            value: DRAFTS[currentLang] || DEFAULTS[currentLang] || '',
            language: LANG_META[currentLang] || 'python',
        });
        setInterval(() => saveDraft(false), 5000);
        editor.onDidChangeModelContent(() => { dirtySinceSave = true; });
    } catch (e) {
        el('console-output').innerText = 'Không tải được bộ soạn thảo (cần mạng để tải Monaco từ CDN).';
    }
    window.addEventListener('beforeunload', (ev) => {
        if (dirtySinceSave) ev.preventDefault();
    });
}

export function changeLanguage() {
    if (!editor) return;
    DRAFTS[currentLang] = editor.getValue();
    currentLang = el('lang-select').value;
    setEditorLanguage(editor, LANG_META[currentLang]);
    editor.setValue(DRAFTS[currentLang] || '');
}

export async function saveDraft(manual = false) {
    if (!editor) return;
    try {
        const { data } = await apiFetch('/save_draft.php', {
            method: 'POST',
            body: { problem_id: CFG.problemId, language: currentLang, source_code: editor.getValue() },
        });
        dirtySinceSave = false;
        if (data.ok) {
            el('draft-status').innerText = (manual ? '✔ Đã lưu nháp lúc ' : 'Tự động lưu: ') + data.saved_at;
        } else if (manual) {
            el('draft-status').innerText = '⚠ Lưu nháp thất bại, hãy thử lại.';
        }
    } catch (err) {
        if (manual) el('draft-status').innerText = '⚠ Mất kết nối, chưa lưu được nháp.';
    }
}

export async function runCustomCode() {
    el('console-badge').innerText = 'ĐANG CHẠY THỬ...';
    el('console-output').innerText = 'Đang gửi mã nguồn tới dịch vụ thực thi cách ly...';
    let customRunId = null;
    try {
        const { data } = await apiFetch('/run_custom.php', {
            method: 'POST',
            body: { language: currentLang, source_code: editor.getValue(), stdin: el('custom-stdin').value },
        });
        if (!data.ok) {
            el('console-badge').innerText = 'LỖI';
            el('console-output').innerText = 'Lỗi: ' + (data.error || 'Không tạo được phiên chạy thử.');
            return;
        }
        customRunId = data.custom_run_id;
    } catch (err) {
        el('console-badge').innerText = 'MẤT KẾT NỐI';
        el('console-output').innerText = 'Không gửi được mã nguồn. Kiểm tra mạng rồi thử lại.';
        return;
    }
    pollUntilDone({
        fetchOnce: async () => {
            const { res, data } = await apiFetch('/run_custom.php?id=' + customRunId);
            if (res.status === 401) throw new Error('auth');
            return data.ok ? data.data : null;
        },
        isDone: (d) => d && d.status === 'DONE',
        onDone: (d) => renderCustomResult(d),
        onTimeout: (msg) => {
            el('console-badge').innerText = 'HẾT GIỜ CHỜ';
            el('console-output').innerText = msg + ' Mã nguồn của bạn vẫn được giữ nguyên, hãy thử lại.';
        },
        intervalMs: 500,
        maxPolls: 40,
        timeoutText: 'Dịch vụ thực thi không phản hồi sau 20 giây.',
    });
}

function renderCustomResult(d) {
    el('console-badge').innerText = d.verdict + ' (' + d.execution_time_ms + ' ms)';
    el('console-output').innerText = formatCustomResult(d);
}

export async function submitSolution(isRetrySameRequest = false) {
    if (!isRetrySameRequest) {
        lastIdempotencyKey = 'req_' + Date.now() + '_' + Math.random().toString(36).substring(2, 8);
        el('idem-display').innerText = lastIdempotencyKey;
    }
    el('console-badge').innerText = 'QUEUED';
    el('console-output').innerText = 'Đang đưa bài nộp vào hàng đợi chấm...';
    let data = null;
    try {
        ({ data } = await apiFetch('/submit.php', {
            method: 'POST',
            body: { problem_id: CFG.problemId, language: currentLang, source_code: editor.getValue(), idempotency_key: lastIdempotencyKey },
        }));
    } catch (err) {
        el('console-output').innerText = 'Lỗi: mất kết nối khi nộp bài.';
        return;
    }
    if (!data.ok) {
        el('console-output').innerText = 'Lỗi: ' + data.error;
        return;
    }
    if (data.duplicate_prevented) {
        el('console-output').innerText = '[CHỐNG NỘP TRÙNG - IDEMPOTENT]\nYêu cầu gửi lại dùng cùng khóa (' + lastIdempotencyKey + ').\nHệ thống KHÔNG tạo bản nộp trùng mà trả về đúng Bài nộp #' + data.submission_id + '!';
    }
    pollSubmissionStatus(data.submission_id);
}

export function pollSubmissionStatus(submissionId) {
    pollUntilDone({
        fetchOnce: async () => {
            const { res, data } = await apiFetch('/submission_status.php?submission_id=' + submissionId);
            if (res.status === 401) throw new Error('auth');
            return data.ok ? data : null;
        },
        isDone: (data) => data && data.submission
            && data.submission.final_verdict !== 'QUEUED' && data.submission.final_verdict !== 'RUNNING',
        onDone: (data) => {
            const sub = data.submission;
            el('console-badge').innerText = sub.final_verdict + ` (${sub.pass_count}/${sub.total_cases} test)`;
            renderSubmissionResult(data);
        },
        onTimeout: () => {
            el('console-badge').innerText = 'HẾT GIỜ CHỜ';
            el('console-output').innerText = 'Máy chấm không phản hồi sau 60 giây (có thể hàng đợi đang tắc). Bài nộp của bạn vẫn được giữ, hãy tải lại trang để xem kết quả sau.';
        },
        intervalMs: 600,
        maxPolls: 100,
        timeoutText: '',
    });
}

function renderSubmissionResult(data) {
    const sub = data.submission;
    let lines = `=== KẾT QUẢ CHẤM BÀI NỘP #${sub.id} (Bộ dữ liệu v${sub.version_number}.0) ===\n`;
    lines += `Trạng thái: ${sub.final_verdict} | Điểm: ${sub.final_score}/100 | Vượt qua: ${sub.pass_count}/${sub.total_cases} ca\n\n`;
    data.active_run_cases.forEach((tc) => {
        lines += `• Test #${tc.case_order} (${tc.is_public ? 'Công khai' : 'Test kín'}${tc.is_boundary ? ' - Biên' : ''}): [${tc.verdict}] - ${tc.execution_time_ms} ms\n`;
        if (tc.is_public) {
            lines += `   Stdin: ${tc.stdin_preview.replace(/\n/g, ' ')} | Đáp án: ${tc.expected_preview} | Của bạn: ${tc.actual_preview}\n`;
        }
    });
    el('console-output').innerText = lines;

    let html = `<div style="background:#f8fafc; padding:12px; border-radius:8px; margin-bottom:14px;">
        <strong>Bài nộp #${sub.id}</strong> — Ngôn ngữ: <code>${sub.language}</code> — Đề v<strong>${sub.version_number}.0</strong>
        <span class="badge verdict-${sub.final_verdict}" style="margin-left:8px;">${sub.final_verdict} (${sub.final_score} đ)</span>
    </div>`;
    html += `<h4 style="margin-bottom:8px;">Các lần thực thi (Judging Runs):</h4>
    <table style="width:100%; border-collapse:collapse; font-size:0.85rem; margin-bottom:16px;">
        <tr style="background:#f1f5f9;"><th style="padding:8px;">Run ID</th><th>Loại</th><th>Bộ Test</th><th>Kết quả</th><th>Số ca đúng</th><th>Max time</th></tr>`;
    data.runs_history.forEach((r) => {
        html += `<tr style="border-bottom:1px solid #e2e8f0;">
            <td style="padding:8px;">#${r.id} (Lần ${r.run_number})</td>
            <td>${r.trigger_type}</td>
            <td>v${r.version_number}.0</td>
            <td><span class="badge verdict-${r.verdict}">${r.verdict}</span></td>
            <td>${r.pass_count}/${r.total_cases}</td>
            <td>${r.max_time_ms} ms</td>
        </tr>`;
    });
    html += `</table>`;
    el('submission-detail-box').innerHTML = html;
}

export function switchLeftTab(tab, btn) {
    document.querySelectorAll('.ide-tab').forEach((t) => t.classList.remove('active'));
    btn.classList.add('active');
    el('tab-desc').style.display = tab === 'desc' ? 'block' : 'none';
    el('tab-history').style.display = tab === 'history' ? 'block' : 'none';
}

// Alpine component cho workspace: x-data="workspace".
// Đăng ký qua Alpine.data trong sự kiện alpine:init để không phụ thuộc
// thứ tự tải giữa Alpine (defer) và ES module (luôn chạy sau defer).
function workspaceComponent() {
    return {
        init() { initWorkspace(); },
        changeLanguage() { changeLanguage(); },
        saveDraft(manual) { saveDraft(manual); },
        runCustomCode() { runCustomCode(); },
        submitSolution(retry) { submitSolution(retry); },
    };
}

if (window.Alpine) {
    window.Alpine.data('workspace', workspaceComponent);
} else {
    document.addEventListener('alpine:init', () => window.Alpine.data('workspace', workspaceComponent));
}
window.workspace = workspaceComponent;
alpineReady();

// Giữ hàm global cho onclick cũ.
window.changeLanguage = changeLanguage;
window.saveDraft = saveDraft;
window.runCustomCode = runCustomCode;
window.submitSolution = submitSolution;
window.switchLeftTab = switchLeftTab;
