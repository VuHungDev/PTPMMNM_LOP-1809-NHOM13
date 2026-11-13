// Render public output comparisons without interpreting submitted stdout as HTML.
function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
}

function outputBlock(title, value, className = '') {
    const block = element('div', 'submission-output-block ' + className);
    const label = element('div', 'submission-output-label', title);
    const text = value == null ? '' : String(value);
    const pre = element('pre', 'submission-output-text', text === '' ? '(không có đầu ra)' : text);
    block.append(label, pre);
    return block;
}

export function renderSubmissionConsole(container, data) {
    const sub = data.submission;
    const summary = element('div', 'submission-result-summary');
    summary.append(
        element('strong', '', `Bài nộp #${sub.id} · Bộ dữ liệu v${sub.version_number}.0`),
        element('div', '', `Trạng thái: ${sub.final_verdict} | Điểm: ${sub.final_score}/100 | Vượt qua: ${sub.pass_count}/${sub.total_cases} ca`),
    );
    const content = [summary];
    if (sub.final_verdict === 'WA') {
        content.push(element('p', 'submission-result-hint',
            'WA (Sai đáp án): đầu ra chưa khớp đáp án. Đối chiếu các test công khai bên dưới; không in lời nhắc, nhãn kết quả hoặc debug nếu đề không yêu cầu.'));
    }

    for (const tc of data.active_run_cases || []) {
        // Hidden cases never render input, expected output or actual output, even
        // if a future API accidentally includes them in the response.
        const isPublic = tc.is_public === true || tc.is_public === 1;
        const label = `Test #${tc.case_order} (${isPublic ? 'Công khai' : 'Test kín'}${tc.is_boundary ? ' - Biên' : ''}): [${tc.verdict}] · ${tc.execution_time_ms} ms`;
        if (!isPublic) {
            content.push(element('div', 'submission-case-private', label + ' — Dữ liệu đã ẩn.'));
            continue;
        }

        const details = element('details', 'submission-case' + (tc.verdict === 'WA' ? ' submission-case-wa' : ''));
        details.open = tc.verdict !== 'AC';
        details.append(element('summary', '', label));
        const input = outputBlock('Đầu vào (stdin)', tc.stdin_preview);
        if (tc.stdin_preview === '') input.lastElementChild.textContent = '(đầu vào rỗng)';
        const comparison = element('div', 'submission-output-comparison');
        comparison.append(
            outputBlock('Đáp án mong đợi', tc.expected_preview, 'submission-output-expected'),
            outputBlock('Đầu ra của bạn (stdout)', tc.actual_preview, 'submission-output-actual'),
        );
        details.append(input, comparison);
        if (tc.actual_truncated) {
            details.append(element('p', 'submission-preview-note', 'Đầu ra của bạn quá dài; chỉ hiển thị 300 ký tự đầu. Máy chấm so sánh toàn bộ đầu ra, không chỉ phần xem trước.'));
        }
        content.push(details);
    }
    container.replaceChildren(...content);
}
