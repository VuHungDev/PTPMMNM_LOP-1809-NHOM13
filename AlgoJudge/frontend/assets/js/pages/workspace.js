// frontend/assets/js/pages/workspace.js — ES module cho trang làm bài (workspace.php).
// Dùng chung http.js / poll.js / editor.js; cấu hình注入 qua window.WS_CFG từ PHP.
import { apiFetch } from '../modules/http.js?v=20261115-run-3';
import { pollUntilDone, formatCustomResult } from '../modules/poll.js';
import { createCodeEditor, setEditorLanguage } from '../modules/editor.js';
import { ready as alpineReady } from '../modules/alpine-boot.js';
import { renderSubmissionConsole } from '../modules/submission-result.js';

const CFG = window.WS_CFG || {};
const LANG_META = CFG.langMeta || {};
const DEFAULTS = CFG.defaults || {};
const DRAFTS = Object.assign({}, DEFAULTS, CFG.drafts || {});
const draftStates = Object.fromEntries(Object.keys(DRAFTS).map(lang => [lang, {
    savedSource: DRAFTS[lang],
    cleanEditorSource: null,
    editorSource: null,
    baseHash: CFG.draftHashes?.[lang] || 'missing',
    dirty: false,
    conflict: false,
    inFlight: null,
    sentSource: null,
    unconfirmed: false,
    saveQueued: false,
    queuedManual: false,
    status: 'Đã sẵn sàng',
}]));
const DRAFT_CONFLICT = 'Bản nháp đã thay đổi ở tab khác. Mã cục bộ chưa được lưu; hãy sao chép mã trước khi tải lại trang. Không tự động ghi đè.';
let editor = null;
let editorFailed = false;
let consoleGeneration = 0;
let currentLang = CFG.suggestLang || 'python';
let lastIdempotencyKey = 'req_' + Date.now() + '_' + Math.random().toString(36).substring(2, 8);
let changingLanguage = false;
let customRunning = false;

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
    const initialLang = currentLang;
    try {
        editor = await createCodeEditor('monaco-container', {
            value: DRAFTS[initialLang] ?? '',
            language: LANG_META[initialLang] || 'python',
        });
        rememberEditorSource(initialLang);
        if (currentLang !== initialLang) {
            setEditorLanguage(editor, LANG_META[currentLang]);
            editor.setValue(DRAFTS[currentLang] ?? '');
            rememberEditorSource(currentLang);
        }
        setInterval(() => {
            captureDraft(currentLang);
            return Promise.all(Object.keys(draftStates).map(lang => saveLanguageDraft(lang, false)));
        }, 5000);
        editor.onDidChangeModelContent(() => {
            if (changingLanguage) return;
            const state = captureDraft(currentLang);
            if (!state.conflict) state.status = state.dirty ? 'Có thay đổi chưa lưu' : 'Không có thay đổi chưa lưu';
            renderDraftStatus();
        });
    } catch (e) {
        editorFailed = true;
        showEditorUnavailable();
    }
    window.addEventListener('beforeunload', (ev) => {
        if (editor) captureDraft(currentLang);
        if (Object.values(draftStates).some(state => state.dirty)) {
            ev.preventDefault();
            ev.returnValue = '';
        }
    });
}

export function changeLanguage() {
    const nextLang = el('lang-select').value;
    if (!draftStates[nextLang] || nextLang === currentLang) return;
    if (editor) {
        captureDraft(currentLang);
        void saveLanguageDraft(currentLang, false);
    }
    currentLang = nextLang;
    if (!editor) return;
    changingLanguage = true;
    try {
        setEditorLanguage(editor, LANG_META[currentLang]);
        editor.setValue(DRAFTS[currentLang] ?? '');
        rememberEditorSource(currentLang);
    } finally {
        changingLanguage = false;
    }
    renderDraftStatus();
}

function showEditorUnavailable() {
    el('console-badge').innerText = editorFailed ? 'LỖI TẢI BỘ SOẠN THẢO' : 'ĐANG TẢI BỘ SOẠN THẢO';
    el('console-output').innerText = editorFailed
        ? 'Không tải được bộ soạn thảo. Kiểm tra kết nối mạng tới CDN Monaco rồi tải lại trang.'
        : 'Bộ soạn thảo đang tải, vui lòng chờ rồi bấm Chạy thử.';
}

function rememberEditorSource(lang) {
    const state = draftStates[lang];
    // Monaco normalizes mixed EOL on load. Keep raw cached bytes/hash until a real edit.
    const source = editor.getValue();
    if (state.cleanEditorSource === null) state.cleanEditorSource = source;
    state.editorSource = source;
}

function captureDraft(lang) {
    const state = draftStates[lang];
    if (editor && lang === currentLang) {
        const source = editor.getValue();
        if (source !== state.editorSource) {
            DRAFTS[lang] = source === state.cleanEditorSource ? state.savedSource : source;
            state.editorSource = source;
        }
    }
    state.dirty = state.conflict || state.unconfirmed || DRAFTS[lang] !== state.savedSource
        || (state.sentSource !== null && DRAFTS[lang] !== state.sentSource);
    return state;
}

function renderDraftStatus() {
    const conflictLang = Object.keys(draftStates).find(lang => draftStates[lang].conflict);
    el('draft-status').innerText = conflictLang
        ? conflictLang + ': ' + DRAFT_CONFLICT : draftStates[currentLang].status;
}

async function saveLanguageDraft(lang, manual) {
    const state = captureDraft(lang);
    if (state.conflict) {
        renderDraftStatus();
        return;
    }
    if (!state.dirty) {
        if (manual) {
            state.status = 'Không có thay đổi chưa lưu';
            renderDraftStatus();
        }
        return;
    }
    if (state.inFlight) {
        state.saveQueued = true;
        state.queuedManual ||= manual;
        return state.inFlight;
    }
    const source = DRAFTS[lang];
    const editorSource = state.editorSource;
    const baseHash = state.baseHash;
    state.sentSource = source;
    state.status = 'Đang lưu nháp...';
    renderDraftStatus();
    state.inFlight = (async () => {
        let acknowledged = false;
        try {
            const { res, data } = await apiFetch('/save_draft.php', {
                method: 'POST',
                timeoutMs: 30000,
                body: { problem_id: CFG.problemId, language: lang, source_code: source, base_hash: baseHash },
            });
            if (res?.status === 409) {
                state.conflict = true;
                state.status = DRAFT_CONFLICT;
            } else if (res?.status >= 200 && res.status < 300 && data?.ok === true
                && typeof data.base_hash === 'string' && /^[a-f0-9]{64}$/.test(data.base_hash)
                && typeof data.saved_at === 'string') {
                acknowledged = true;
                state.savedSource = source;
                state.cleanEditorSource = editorSource;
                state.baseHash = data.base_hash;
                state.unconfirmed = false;
                state.status = (manual ? 'Đã lưu nháp lúc ' : 'Tự động lưu: ') + data.saved_at;
            } else {
                state.unconfirmed = true;
                state.status = 'Lưu nháp thất bại. Mã cục bộ vẫn được giữ; hãy thử lại.';
            }
        } catch (err) {
            state.unconfirmed = true;
            state.status = 'Mất kết nối, chưa xác nhận lưu nháp. Mã cục bộ vẫn được giữ.';
        } finally {
            state.inFlight = null;
            state.sentSource = null;
            captureDraft(lang);
            if (acknowledged && state.dirty) state.status = 'Có thay đổi mới chưa lưu';
            renderDraftStatus();
            const queued = state.saveQueued;
            const queuedManual = state.queuedManual;
            state.saveQueued = false;
            state.queuedManual = false;
            // Chỉ nối tiếp yêu cầu đã xếp hàng sau ACK hợp lệ, không thử lại xung đột.
            if (acknowledged && queued && state.dirty) await saveLanguageDraft(lang, queuedManual);
        }
    })();
    return state.inFlight;
}

export async function saveDraft(manual = false) {
    if (!editor) return;
    return saveLanguageDraft(currentLang, manual);
}

export async function runCustomCode() {
    if (customRunning) return;
    const generation = ++consoleGeneration;
    if (!editor) {
        showEditorUnavailable();
        return;
    }
    customRunning = true;
    const btn = el('custom-run-btn');
    btn.disabled = true;
    let settled = false;
    const finish = () => {
        if (settled) return false;
        settled = true;
        customRunning = false;
        btn.disabled = false;
        return true;
    };
    el('console-badge').innerText = 'ĐANG CHẠY THỬ...';
    el('console-output').innerText = 'Đang gửi mã nguồn tới dịch vụ thực thi cách ly...';
    let customRunId = null;
    try {
        const { data } = await apiFetch('/run_custom.php', {
            method: 'POST',
            timeoutMs: 90000,
            body: { language: currentLang, source_code: editor.getValue(), stdin: el('custom-stdin').value },
        });
        if (!data.ok) {
            if (generation === consoleGeneration) {
                el('console-badge').innerText = 'LỖI';
                el('console-output').innerText = 'Lỗi: ' + (data.error || 'Không tạo được phiên chạy thử.');
            }
            return;
        }
        customRunId = data.custom_run_id;
    } catch (err) {
        if (generation === consoleGeneration) {
            if (err.name === 'TimeoutError') {
                el('console-badge').innerText = 'HẾT GIỜ CHỜ';
                el('console-output').innerText = 'Chưa nhận được phản hồi sau 90 giây. Chưa biết kết quả; phiên chạy có thể vẫn đang xử lý trên máy chủ. Mã nguồn và dữ liệu nhập vẫn được giữ nguyên. Không tự động gửi lại.';
            } else {
                el('console-badge').innerText = 'MẤT KẾT NỐI';
                el('console-output').innerText = 'Không gửi được mã nguồn. Kiểm tra mạng rồi thử lại.';
            }
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
            if (!finish() || generation !== consoleGeneration) return;
            renderCustomResult(d);
        },
        onTimeout: (msg) => {
            if (!finish() || generation !== consoleGeneration) return;
            el('console-badge').innerText = 'HẾT GIỜ CHỜ';
            el('console-output').innerText = msg + ' Mã nguồn của bạn vẫn được giữ nguyên, hãy thử lại.';
        },
        intervalMs: 500,
        maxPolls: 180,
        timeoutText: 'Chưa nhận được kết quả sau 90 giây. Phiên chạy có thể vẫn đang xử lý.',
    });
}

function renderCustomResult(d) {
    el('console-badge').innerText = d.verdict + ' (' + d.execution_time_ms + ' ms)';
    el('console-output').innerText = formatCustomResult(d);
}

export async function submitSolution(isRetrySameRequest = false) {
    const generation = ++consoleGeneration;
    if (!editor) {
        showEditorUnavailable();
        return;
    }
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
        if (generation === consoleGeneration) el('console-output').innerText = 'Lỗi: mất kết nối khi nộp bài.';
        return;
    }
    if (generation !== consoleGeneration) return;
    if (!data.ok) {
        el('console-output').innerText = 'Lỗi: ' + data.error;
        return;
    }
    if (data.duplicate_prevented) {
        el('console-output').innerText = '[CHỐNG NỘP TRÙNG - IDEMPOTENT]\nYêu cầu gửi lại dùng cùng khóa (' + lastIdempotencyKey + ').\nHệ thống KHÔNG tạo bản nộp trùng mà trả về đúng Bài nộp #' + data.submission_id + '!';
    }
    pollSubmissionStatus(data.submission_id, generation);
}

export function pollSubmissionStatus(submissionId, generation = ++consoleGeneration) {
    let settled = false;
    pollUntilDone({
        fetchOnce: async () => {
            const { res, data } = await apiFetch('/submission_status.php?submission_id=' + submissionId);
            if (res.status === 401) throw new Error('auth');
            return data.ok ? data : null;
        },
        isDone: (data) => data && data.submission
            && data.submission.final_verdict !== 'QUEUED' && data.submission.final_verdict !== 'RUNNING',
        onDone: (data) => {
            if (settled || generation !== consoleGeneration) return;
            settled = true;
            const sub = data.submission;
            el('console-badge').innerText = sub.final_verdict + ` (${sub.pass_count}/${sub.total_cases} test)`;
            renderSubmissionResult(data);
        },
        onTimeout: () => {
            if (settled || generation !== consoleGeneration) return;
            settled = true;
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
    renderSubmissionConsole(el('console-output'), data);

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
