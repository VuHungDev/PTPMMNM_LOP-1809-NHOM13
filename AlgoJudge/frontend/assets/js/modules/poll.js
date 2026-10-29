// frontend/assets/js/modules/poll.js — ES module: polling kết quả chấm/chạy thử có giới hạn.
export function pollUntilDone({
    fetchOnce,
    isDone,
    onDone,
    onTimeout,
    intervalMs = 500,
    maxPolls = 40,
    timeoutText = 'Máy chấm không phản hồi, hãy thử lại.',
}) {
    let polls = 0;
    const timer = setInterval(async () => {
        polls++;
        if (polls > maxPolls) {
            clearInterval(timer);
            if (typeof onTimeout === 'function') onTimeout(timeoutText);
            return;
        }
        let data = null;
        try {
            data = await fetchOnce();
        } catch (e) {
            return;
        }
        if (data && isDone(data)) {
            clearInterval(timer);
            onDone(data);
        }
    }, intervalMs);
    return () => clearInterval(timer);
}

// Render thống nhất kết quả chạy thử: stdout + lỗi rút gọn theo dòng + gợi ý.
export function formatCustomResult(d) {
    let text = d.stdout_output ? '[Đầu ra Stdout]:\n' + d.stdout_output + '\n' : '';
    if ((d.verdict === 'CE' || d.verdict === 'RE') && (d.error_line || d.error_message)) {
        text += '\n⛔ Lỗi tại dòng ' + (d.error_line || '?') + ': ' + (d.error_message || '') + '\n';
        if (d.error_hint) text += '💡 Gợi ý: ' + d.error_hint + '\n';
    }
    if (d.stderr_output && d.verdict !== 'OK') {
        text += (d.verdict === 'CE' || d.verdict === 'RE' ? '\n— Chi tiết đầy đủ —\n' : '[Thông báo lỗi]:\n') + d.stderr_output;
    }
    return text || '(không có đầu ra)';
}
