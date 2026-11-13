// Dependency-free DOM contract tests. No HTTP, accounts, database or browser downloads.
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

class TestElement {
    constructor(tagName) {
        this.tagName = tagName;
        this.className = '';
        this.children = [];
        this.ownText = '';
        this.open = false;
    }
    set textContent(value) { this.ownText = String(value); this.children = []; }
    get textContent() { return this.ownText + this.children.map(child => child.textContent).join(''); }
    set innerHTML(_) { throw new Error('Do not interpret submitted output as HTML'); }
    append(...nodes) { this.children.push(...nodes); }
    replaceChildren(...nodes) { this.ownText = ''; this.children = [...nodes]; }
    get lastElementChild() { return this.children.at(-1); }
}
globalThis.document = { createElement: tag => new TestElement(tag) };
const moduleSource = await readFile(new URL('../../frontend/assets/js/modules/submission-result.js', import.meta.url), 'utf8');
const { renderSubmissionConsole } = await import('data:text/javascript;base64,' + Buffer.from(moduleSource).toString('base64'));
function descendants(node) { return [node, ...node.children.flatMap(descendants)]; }
function fixture(overrides = {}) {
    return {
        submission: { id: 35, version_number: 1, final_verdict: 'WA', final_score: 0, pass_count: 0, total_cases: 2 },
        active_run_cases: [
            { case_order: 1, is_public: true, is_boundary: false, verdict: 'WA', execution_time_ms: 105,
                stdin_preview: '5', expected_preview: '120', actual_preview: 'Nhập N: 5! = 120\n' },
            { case_order: 2, is_public: false, is_boundary: true, verdict: 'WA', execution_time_ms: 100,
                stdin_preview: 'SECRET_STDIN', expected_preview: 'SECRET_EXPECTED', actual_preview: 'SECRET_ACTUAL' },
        ],
        ...overrides,
    };
}
let checks = 0;
function check(label, fn) { fn(); checks++; console.log('PASS ' + label); }

check('public WA renders separate input, expected and actual blocks; failed case is expanded', () => {
    const container = new TestElement('div');
    renderSubmissionConsole(container, fixture());
    const nodes = descendants(container);
    const blocks = nodes.filter(node => node.tagName === 'pre');
    assert.deepEqual(blocks.map(node => node.textContent), ['5', '120', 'Nhập N: 5! = 120\n']);
    assert.equal(nodes.find(node => node.tagName === 'details').open, true);
    assert.match(container.textContent, /WA \(Sai đáp án\)/);
    assert.match(container.textContent, /Đáp án mong đợi/);
    assert.match(container.textContent, /Đầu ra của bạn \(stdout\)/);
});
check('hidden cases never render stdin, expected, actual or truncation hints', () => {
    const data = fixture();
    data.active_run_cases[1].actual_truncated = true;
    const container = new TestElement('div');
    renderSubmissionConsole(container, data);
    assert.doesNotMatch(container.textContent, /SECRET_|300 ký tự/);
    assert.match(container.textContent, /Test kín - Biên/);
    assert.equal(descendants(container).filter(node => node.tagName === 'details').length, 1);
});
check('only explicit public boolean or integer flags can reveal a comparison', () => {
    for (const publicFlag of [true, 1, false, 0, '0', '1', 'false', null, undefined]) {
        const data = fixture();
        data.active_run_cases = [{
            ...data.active_run_cases[0],
            is_public: publicFlag,
            stdin_preview: 'PRIVATE_INPUT_MARKER',
            expected_preview: 'PRIVATE_EXPECTED_MARKER',
            actual_preview: 'PRIVATE_ACTUAL_MARKER',
        }];
        const container = new TestElement('div');
        renderSubmissionConsole(container, data);
        const explicitlyPublic = publicFlag === true || publicFlag === 1;
        assert.equal(container.textContent.includes('PRIVATE_INPUT_MARKER'), explicitlyPublic);
        assert.equal(container.textContent.includes('PRIVATE_EXPECTED_MARKER'), explicitlyPublic);
        assert.equal(container.textContent.includes('PRIVATE_ACTUAL_MARKER'), explicitlyPublic);
    }
});
check('stdout resembling HTML is rendered as literal text', () => {
    const data = fixture();
    const payload = '<img src=x onerror="alert(1)"><script>alert(1)</script>';
    data.active_run_cases[0].actual_preview = payload;
    const container = new TestElement('div');
    renderSubmissionConsole(container, data);
    assert.ok(descendants(container).some(node => node.tagName === 'pre' && node.textContent === payload));
    assert.ok(!descendants(container).some(node => ['img', 'script'].includes(node.tagName)));
});
check('empty actual output has an explicit placeholder; multiline Unicode stays intact', () => {
    const data = fixture();
    data.active_run_cases[0].stdin_preview = '';
    data.active_run_cases[0].actual_preview = '';
    data.active_run_cases[0].expected_preview = 'Dòng 1\nDòng 2: 120';
    const container = new TestElement('div');
    renderSubmissionConsole(container, data);
    const blocks = descendants(container).filter(node => node.tagName === 'pre');
    assert.deepEqual(blocks.map(node => node.textContent), ['(đầu vào rỗng)', 'Dòng 1\nDòng 2: 120', '(không có đầu ra)']);
});
check('only truncated public stdout displays the preview warning', () => {
    const data = fixture();
    data.active_run_cases[0].actual_truncated = true;
    const container = new TestElement('div');
    renderSubmissionConsole(container, data);
    assert.match(container.textContent, /300 ký tự đầu/);
    assert.match(container.textContent, /so sánh toàn bộ đầu ra/);
});
check('accepted public test is collapsible and has no WA warning', () => {
    const data = fixture();
    data.submission.final_verdict = 'AC';
    data.submission.pass_count = 2;
    data.submission.final_score = 100;
    data.active_run_cases[0].verdict = 'AC';
    data.active_run_cases[0].actual_preview = '120\n';
    const container = new TestElement('div');
    renderSubmissionConsole(container, data);
    assert.equal(descendants(container).find(node => node.tagName === 'details').open, false);
    assert.doesNotMatch(container.textContent, /WA \(Sai đáp án\)/);
});
check('a preview does not decide WA and non-AC public results remain expanded', () => {
    const data = fixture();
    const tc = data.active_run_cases[0];
    tc.actual_preview = tc.expected_preview;
    tc.actual_truncated = true;
    const container = new TestElement('div');
    renderSubmissionConsole(container, data);
    assert.match(container.textContent, /Trạng thái: WA/);
    assert.match(container.textContent, /so sánh toàn bộ đầu ra/);
    assert.equal(descendants(container).find(node => node.tagName === 'details').open, true);
    for (const verdict of ['RE', 'TLE', 'SYS_ERR']) {
        tc.verdict = verdict;
        renderSubmissionConsole(container, data);
        assert.equal(descendants(container).find(node => node.tagName === 'details').open, true);
        assert.ok(container.textContent.includes('[' + verdict + ']'));
    }
});
check('rerender replaces old results rather than appending stale cases', () => {
    const container = new TestElement('div');
    renderSubmissionConsole(container, fixture());
    renderSubmissionConsole(container, fixture({ active_run_cases: [] }));
    assert.equal(descendants(container).filter(node => node.tagName === 'details').length, 0);
    assert.doesNotMatch(container.textContent, /Nhập N:/);
});
const page = await readFile(new URL('../../frontend/workspace.php', import.meta.url), 'utf8');
const workspace = await readFile(new URL('../../frontend/assets/js/pages/workspace.js', import.meta.url), 'utf8');
const playground = await readFile(new URL('../../frontend/playground.php', import.meta.url), 'utf8');
const styles = await readFile(new URL('../../frontend/assets/css/algojudge.css', import.meta.url), 'utf8');
check('responsive workspace controls are scoped separately from the playground', () => {
    assert.match(page, /class="ide-wrapper workspace-ide"/);
    assert.match(page, /class="workspace-toolbar-actions"/);
    assert.match(page, /class="workspace-toolbar-language"/);
    assert.match(page, /label for="lang-select"/);
    assert.match(styles, /\.workspace-ide \.ide-toolbar\s*\{[^}]*height: auto;[^}]*flex-wrap: wrap;/);
    assert.match(styles, /@media \(max-width: 900px\)\s*\{\s*\.workspace-ide\s*\{[^}]*grid-template-columns: minmax\(0, 1fr\)/);
    assert.doesNotMatch(playground, /workspace-ide|workspace-toolbar-actions/);
    assert.doesNotMatch(styles, /\.ide-right \.ide-console\s*\{\s*flex: 0 0 300px/);
    assert.match(styles, /\.workspace-ide \.ide-console\s*\{\s*flex: 0 1 300px;[^}]*min-height: 0;/);
    assert.match(styles, /\.workspace-ide \.console-header\s*\{\s*flex-shrink: 0;/);
});
check('page omits the redundant output guide and keeps the structured renderer', () => {
    assert.doesNotMatch(page, /workspace-output-guide|output-guide-title|Quy tắc nhập \/ xuất khi nộp bài/);
    assert.doesNotMatch(styles, /\.workspace-output-guide/);
    assert.match(page, /Đầu ra \(Output\)/);
    assert.match(page, /Ví dụ công khai \(Public Sample Tests\)/);
    assert.match(workspace, /renderSubmissionConsole\(el\('console-output'\), data\)/);
    assert.doesNotMatch(workspace, /Stdin: \$\{tc\.stdin_preview/);
});
console.log(`Workspace output: ${checks} scenarios passed, including page wiring.`);
