// frontend/assets/js/pages/instructor.js — ES module: chấm lại / thử lại / thêm dòng test case.
import { apiFetch } from '../modules/http.js';
import { ready as alpineReady } from '../modules/alpine-boot.js';

async function confirmAction(message) {
    return window.confirm(message);
}

export async function rejudgeSubmission(subId, verId) {
    let data = null;
    try {
        ({ data } = await apiFetch('/rejudge.php', {
            method: 'POST',
            body: { submission_id: subId, problem_version_id: verId },
        }));
    } catch (err) {
        alert('Mất kết nối, hãy thử lại.');
        return;
    }
    if (data.ok) {
        alert(`Đã chấm lại thành công Bài nộp #${subId} (Lần chấm thứ ${data.run_number}): Kết quả ${data.verdict} (${data.score} điểm). Lịch sử các lần chấm trước vẫn được giữ nguyên!`);
        window.location.reload();
    } else {
        alert('Lỗi: ' + data.error);
    }
}

export async function retryRun(runId) {
    if (!(await confirmAction(`Thử lại Run #${runId}? Hệ thống tạo lượt chấm mới (SYS_RETRY), giữ nguyên lịch sử cũ.`))) return;
    let data = null;
    try {
        ({ data } = await apiFetch('/retry_run.php', {
            method: 'POST',
            body: { run_id: runId },
        }));
    } catch (err) {
        alert('Mất kết nối, hãy thử lại.');
        return;
    }
    if (data.ok) {
        alert(`Đã thử lại Run #${runId} → Run mới #${data.new_run_id} (lần ${data.run_number}): ${data.verdict} (${data.score}đ).`);
        window.location.reload();
    } else {
        alert('Lỗi: ' + data.error);
    }
}

let caseIdx = 1;

export function addCaseRow(triggerEl) {
    // Số thứ tự bắt đầu lấy từ data-case-start (trang problems.php) hoặc số dòng hiện có.
    const startAttr = triggerEl && triggerEl.dataset ? parseInt(triggerEl.dataset.caseStart, 10) : NaN;
    if (!Number.isNaN(startAttr)) {
        caseIdx = startAttr;
    } else {
        const list0 = document.getElementById('case-list');
        if (list0) caseIdx = Math.max(caseIdx, list0.querySelectorAll('.case-row').length);
    }
    const list = document.getElementById('case-list');
    if (!list) return;
    const div = document.createElement('div');
    div.className = 'case-row';
    div.style.cssText = 'border:1px solid var(--cl-border); border-radius:8px; padding:10px; margin:8px 0;';
    div.innerHTML = '<div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">'
        + '<textarea name="test_stdin[]" class="form-control" rows="2" placeholder="Stdin"></textarea>'
        + '<textarea name="test_expected[]" class="form-control" rows="2" placeholder="Expected"></textarea></div>'
        + `<label style="font-size:0.82rem; margin-right:12px;"><input type="checkbox" name="test_public[${caseIdx}]" value="1"> Công khai</label>`
        + `<label style="font-size:0.82rem;"><input type="checkbox" name="test_boundary[${caseIdx}]" value="1"> Dữ liệu biên</label>`;
    list.appendChild(div);
    caseIdx++;
}

function instructorComponent() {
    return { rejudgeSubmission, retryRun, addCaseRow: ($event) => addCaseRow($event && $event.currentTarget) };
}

if (window.Alpine) {
    window.Alpine.data('instructorPage', instructorComponent);
} else {
    document.addEventListener('alpine:init', () => window.Alpine.data('instructorPage', instructorComponent));
}
window.instructorPage = instructorComponent;
window.rejudgeSubmission = rejudgeSubmission;
window.retryRun = retryRun;
window.addCaseRow = () => addCaseRow();
alpineReady();
