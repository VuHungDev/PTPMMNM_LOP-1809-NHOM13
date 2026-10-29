// frontend/assets/js/pages/submissions.js — ES module: modal xem mã nguồn + lịch sử run.
import { apiFetch } from '../modules/http.js';
import { ready as alpineReady } from '../modules/alpine-boot.js';

async function viewSourceModal(subId) {
    let data = null;
    try {
        ({ data } = await apiFetch('/submission_status.php?submission_id=' + subId));
    } catch (err) {
        alert('Mất kết nối, hãy thử lại.');
        return;
    }
    if (!data.ok) {
        alert(data.error);
        return;
    }
    document.getElementById('modal-title').innerText = `Bài nộp #${data.submission.id} của ${data.submission.username} (Bộ test v${data.submission.version_number}.0)`;
    document.getElementById('modal-code').innerText = data.submission.source_code;
    let html = '<h4>Kết quả từng Test Case (Ẩn toàn bộ dữ liệu chấm kín):</h4><ul style="margin-top:8px; line-height:1.8;">';
    data.active_run_cases.forEach((c) => {
        html += `<li>Test #${c.case_order} (${c.is_public ? 'Công khai' : 'Test kín'}): <strong>${c.verdict}</strong> (${c.execution_time_ms}ms) — Input: <code>${c.stdin_preview}</code></li>`;
    });
    html += '</ul>';
    document.getElementById('modal-cases').innerHTML = html;
    document.getElementById('code-modal').style.display = 'flex';
}

function submissionsComponent() {
    return { viewSourceModal };
}

if (window.Alpine) {
    window.Alpine.data('submissionsPage', submissionsComponent);
} else {
    document.addEventListener('alpine:init', () => window.Alpine.data('submissionsPage', submissionsComponent));
}
window.submissionsPage = submissionsComponent;
window.viewSourceModal = viewSourceModal;
alpineReady();
