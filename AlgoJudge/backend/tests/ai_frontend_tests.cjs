// Offline regressions: node backend/tests/ai_frontend_tests.cjs
// No app bootstrap, database, browser, network, or provider calls are made.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');
const { execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const paths = {
    chat: 'frontend/instructor/ai_chat.php',
    widget: 'frontend/partials/ai_widget.php',
    form: 'frontend/instructor/problem_form.php',
};
const sources = Object.fromEntries(Object.entries(paths).map(([key, file]) => [key, fs.readFileSync(path.join(root, file), 'utf8')]));

function scripts(source, flags = {}) {
    return [...source.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)].map(m => m[1].replace(/<\?=([\s\S]*?)\?>/g, (_, php) => {
        if (php.includes('url(')) return '/test';
        if (php.includes('errStep')) return '1';
        if (php.includes('$isEdit')) return flags.isEdit ? 'true' : 'false';
        if (php.includes("$user['role']")) return flags.isStaff ? 'true' : 'false';
        if (php.includes('$err ?')) return flags.hasServerErr ? 'true' : 'false';
        if (php.includes("'true'")) return 'false';
        return '0';
    }));
}

// A deliberately small DOM double for the selectors/events used by these pages.
class Element {
    constructor(tag = 'div', attrs = {}) {
        this.tagName = tag.toLowerCase();
        this.attrs = attrs;
        this.children = [];
        this.dataset = {};
        this.style = {};
        this.listeners = {};
        this.value = attrs.value || '';
        this.checked = 'checked' in attrs;
        this.hidden = 'hidden' in attrs;
        this.disabled = false;
        this.scrollHeight = 20;
        this._html = '';
        this._text = '';
        for (const [key, val] of Object.entries(attrs)) if (key.startsWith('data-')) this.dataset[key.slice(5)] = val;
        this.classList = { contains: cls => this.className.split(/\s+/).includes(cls) };
    }
    get id() { return this.attrs.id || ''; }
    get name() { return this.attrs.name || ''; }
    set name(value) { this.attrs.name = value; }
    get type() { return this.attrs.type || (this.tagName === 'textarea' ? 'textarea' : ''); }
    get className() { return this.attrs.class || ''; }
    set className(value) { this.attrs.class = value; }
    get lastChild() { return this.children.at(-1); }
    get textContent() { return this._text + this.children.map(c => c.textContent).join(''); }
    set textContent(value) { this._text = String(value); this._html = ''; this.children = []; }
    get innerHTML() { return this._html; }
    set innerHTML(html) {
        this._html = html;
        this._text = '';
        this.children = [];
        const stack = [this];
        for (const token of html.matchAll(/<\/?([a-zA-Z][\w-]*)([^>]*)>|([^<]+)/g)) {
            if (token[3]) { stack.at(-1)._text += token[3]; continue; }
            const tag = token[1].toLowerCase();
            if (token[0].startsWith('</')) {
                if (stack.length > 1 && stack.at(-1).tagName === tag) {
                    const closed = stack.pop();
                    if (tag === 'textarea') closed.value = closed._text;
                    if (tag === 'select') {
                        const options = closed.querySelectorAll('option');
                        const selected = options.find(o => 'selected' in o.attrs) || options[0];
                        closed.value = selected ? (selected.attrs.value ?? selected.textContent) : '';
                    }
                }
                continue;
            }
            const attrs = {};
            for (const a of token[2].matchAll(/([\w-]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+)))?/g)) attrs[a[1]] = a[2] ?? a[3] ?? a[4] ?? '';
            const child = new Element(tag, attrs);
            stack.at(-1).appendChild(child);
            if (!['input', 'br', 'hr', 'meta', 'link', 'img'].includes(tag) && !token[0].endsWith('/>')) stack.push(child);
        }
    }
    matches(selector) {
        const tag = selector.match(/^[\w-]+/);
        if (tag && this.tagName !== tag[0].toLowerCase()) return false;
        const id = selector.match(/#([\w-]+)/);
        if (id && this.id !== id[1]) return false;
        for (const cls of selector.matchAll(/\.([\w-]+)/g)) if (!this.classList.contains(cls[1])) return false;
        for (const attr of selector.matchAll(/\[([\w-]+)(?:=(?:"([^"]*)"|'([^']*)'|([^\]]+)))?\]/g)) {
            if (!(attr[1] in this.attrs)) return false;
            const value = attr[2] ?? attr[3] ?? attr[4];
            if (value !== undefined && this.attrs[attr[1]] !== value) return false;
        }
        return true;
    }
    querySelectorAll(selector) {
        const selectors = selector.split(',').map(s => s.trim());
        const all = this.children.flatMap(c => [c, ...c.descendants()]);
        return all.filter(el => selectors.some(s => {
            const pieces = s.split(/\s+(?![^\[]*\])/);
            if (!el.matches(pieces.pop())) return false;
            let parent = el.parentNode;
            while (pieces.length) {
                const want = pieces.pop();
                while (parent && !parent.matches(want)) parent = parent.parentNode;
                if (!parent) return false;
                parent = parent.parentNode;
            }
            return true;
        }));
    }
    descendants() { return this.children.flatMap(c => [c, ...c.descendants()]); }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
    remove() { this.parentNode.children = this.parentNode.children.filter(c => c !== this); }
    addEventListener(name, cb) { (this.listeners[name] ||= []).push(cb); }
    dispatchEvent(event) { for (const cb of this.listeners[event.type] || []) cb(event); }
    click() { return this.onclick?.({ currentTarget: this, target: this }); }
    focus() {}
    scrollIntoView() {}
}
function storage(seed = {}, failSet = false, events = [], name = '') {
    const data = new Map(Object.entries(seed));
    return {
        get length() { return data.size; }, key: i => [...data.keys()][i],
        getItem: key => data.get(key) ?? null,
        setItem(key, value) { if (failSet) throw Error('storage blocked'); events.push(name + ':set:' + key); data.set(key, value); },
        removeItem(key) { events.push(name + ':remove:' + key); data.delete(key); },
    };
}
const controls = '<input name="title" type="text" id="pf-title"><input name="slug" type="text" id="pf-slug"><select name="topic"><option>Cơ bản</option></select><select name="difficulty"><option>Easy</option></select><textarea name="description" required></textarea><textarea name="input_desc"></textarea><textarea name="output_desc"></textarea><textarea name="sample_input" required></textarea><textarea name="sample_output" required></textarea><input name="time_limit_ms" value="1000"><input name="suggested_lang" value="python">';
function boot(kind, options = {}) {
    const doc = new Element('document');
    const append = (tag, attrs = {}, parent = doc) => parent.appendChild(new Element(tag, attrs));
    if (kind === 'chat') {
        for (const id of ['problem-ai-msgs', 'problem-ai-input', 'problem-ai-send', 'ai-preview-wrap', 'ai-status', 'ai-note', 'ai-src', 'ai-add-case', 'ai-to-form', 'ai-push']) append('div', { id });
        const form = append('form', { id: 'ai-form' });
        form.innerHTML = controls.replaceAll('id="pf-title"', '').replaceAll('id="pf-slug"', '') + '<div id="ai-cases"></div>';
        for (const el of form.querySelectorAll('[name]')) form[el.name] = el;
        form.elements = { namedItem: name => form.querySelector(`[name="${name}"]`) };
    } else if (kind === 'widget') {
        for (const id of ['ai-fab', 'ai-panel', 'ai-msgs', 'ai-input', 'ai-send', 'ai-preview', 'ai-panel-x']) append('div', { id });
    } else {
        for (const id of ['pf-next', 'pf-back', 'pf-submit', 'pf-draft-note', 'pf-draft-clear', 'pf-add-case']) append('div', { id });
        const tabs = append('ol', { id: 'pf-steps' });
        for (let i = 1; i <= 3; i++) append('li', { 'data-step': String(i) }, tabs);
        const form = append('form', { id: 'problem-form' });
        form.innerHTML = '<input name="action" value="create"><input name="csrf_token" value="server-token">' + controls + '<div id="case-list"><div class="case-row"><span class="case-no">1</span><textarea name="test_stdin[]"></textarea><textarea name="test_expected[]"></textarea><input type="checkbox" name="test_public[0]" checked><input type="hidden" name="test_boundary[0]" value="0"></div></div>';
        for (let i = 1; i <= 3; i++) append('section', { 'data-pane': String(i) }, form);
        // Move required fields into the content pane for real validation logic.
        const pane = form.querySelector('[data-pane="2"]');
        for (const el of form.querySelectorAll('[required]')) { el.remove(); pane.appendChild(el); }
    }
    doc.getElementById = id => doc.querySelector('#' + id);
    doc.createElement = tag => new Element(tag);
    const events = [];
    const local = storage(options.local, options.localFails, events, 'local');
    const session = storage(options.session, options.sessionFails, events, 'session');
    const requests = [];
    const context = {
        document: doc, location: { search: options.search || '', href: '' }, localStorage: local, sessionStorage: session,
        URLSearchParams, Event: class { constructor(type) { this.type = type; } },
        FormData: class { constructor(form) { this.form = form; } get(name) { return this.form.querySelector(`[name="${name}"]`)?.value ?? null; } },
        setTimeout: () => 1, clearTimeout() {}, window: { scrollTo() {} }, alert() {},
        fetch: (...args) => { requests.push(args); return options.fetch ? options.fetch(...args) : Promise.reject(Error('unexpected fetch')); },
    };
    const expose = {
        chat: '{ fillPreview, collect, addCaseRow }',
        widget: '{ renderPreview, caseRow }',
        form: '{ collectDraft, validPrefill, validPane }',
    }[kind];
    vm.createContext(context);
    const script = scripts(sources[kind], options)[0].replace(/\}\)\(\);\s*$/, `globalThis.hooks = ${expose};\n})();`);
    vm.runInContext(script, context, { filename: paths[kind] });
    return { ...context, events, requests, el: id => doc.getElementById(id), hooks: context.hooks };
}
const problem = (extra = {}) => ({
    title: 'A title', slug: '', topic: 'Cơ bản', difficulty: 'Easy', description: 'Solve it', input_desc: '', output_desc: '',
    sample_input: '2 3', sample_output: '5', time_limit_ms: 2300,
    test_cases: [{ stdin: '2 3', expected: '5', is_public: false, is_boundary: true }],
    warnings: [], assumptions: [], ready_for_publish: true, needs_review: false, ...extra,
});
function preview(kind, env, data) { env.hooks[kind === 'chat' ? 'fillPreview' : 'renderPreview'](data, 'ai'); }
const ids = kind => kind === 'chat' ? { push: 'ai-push', review: 'ai-to-form', note: 'ai-note', cases: 'ai-cases', row: '.case-row' } : { push: 'ap-push', review: 'ap-review', note: 'ap-note', cases: 'ap-cases', row: '.ai-case' };

for (const kind of Object.keys(paths)) test(`${kind}: extracted inline JavaScript syntax`, () => {
    for (const source of scripts(sources[kind])) new vm.Script(source, { filename: paths[kind] });
});
/* Legacy chatbot preview/handoff behavior was retired; new behavior is exercised
 * by chatbot_frontend_tests.cjs. The manual form regressions below remain live.
for (const kind of ['chat', 'widget']) {
    test(`${kind}: escaped review diagnostics and lossless handoff`, () => {
        const env = boot(kind);
        const title = '\" autofocus onfocus=\"alert(1)\"><img src=x onerror=alert(1)>';
        preview(kind, env, problem({ title, slug: 'generated-slug', warnings: ['<img onerror=alert(1)>'], assumptions: ['<b>assumption</b>'], _note: '<script>legacy</script>' }));
        const id = ids(kind);
        const note = env.el(id.note);
        assert.ok(note.textContent.includes('<img onerror=alert(1)>'));
        assert.ok(note.textContent.includes('Giả định: <b>assumption</b>'));
        assert.ok(note.textContent.includes('output mong đợi'));
        assert.equal(note.querySelectorAll('img, script, b').length, 0);
        if (kind === 'widget') {
            assert.equal(env.el('ap-title').value, title);
            assert.ok(!env.el('ai-preview').innerHTML.includes(title));
        }
        env.el(id.review).click();
        const handoff = JSON.parse(env.sessionStorage.getItem('ai-prefill'));
        assert.equal(handoff.title, title);
        assert.equal(handoff.slug, 'generated-slug');
        assert.equal(handoff.time_limit_ms, 2300);
        assert.equal(handoff.test_cases[0].is_boundary, true);
        assert.equal(handoff.test_cases[0].is_public, false);
        assert.equal(handoff.ready_for_publish, true);
        assert.ok(env.location.href);
    });
    test(`${kind}: storage failure does not navigate`, () => {
        const env = boot(kind, { sessionFails: true });
        preview(kind, env, problem());
        env.el(ids(kind).review).click();
        assert.equal(env.location.href, '');
        assert.equal(env.sessionStorage.getItem('ai-prefill'), null);
    });
    test(`${kind}: sample fallback, blank fallback warning, and no-input fallback`, () => {
        const env = boot(kind);
        const id = ids(kind);
        for (const [data, stdin, expected] of [
            [problem({ test_cases: undefined }), '2 3', '5'],
            [problem({ test_cases: [], sample_output: '' }), '', ''],
            [problem({ test_cases: [], sample_input: '', sample_output: 'Hello', input_desc: 'Không cần dữ liệu đầu vào' }), '', 'Hello'],
        ]) {
            preview(kind, env, data);
            const rows = env.el(id.cases).querySelectorAll(id.row);
            assert.equal(rows.length, 1);
            const values = rows[0].querySelectorAll('textarea').map(t => t.value);
            assert.deepEqual(values, [stdin, expected]);
            assert.ok(env.el(id.note).textContent.includes('Cảnh báo:'));
        }
    });
    test(`${kind}: needs_review blocks direct save but allows manual form handoff`, async () => {
        const env = boot(kind);
        preview(kind, env, problem({ needs_review: true, ready_for_publish: false }));
        const id = ids(kind);
        assert.equal(env.el(id.push).disabled, true);
        await env.el(id.push).click();
        assert.equal(env.requests.length, 0);
        env.el(id.review).click();
        assert.equal(JSON.parse(env.sessionStorage.getItem('ai-prefill')).needs_review, true);
        assert.ok(env.location.href);
    });
    test(`${kind}: direct save guards concurrent requests and locks after success`, async () => {
        let respond;
        const env = boot(kind, { fetch: () => new Promise(resolve => { respond = resolve; }) });
        preview(kind, env, problem());
        const push = env.el(ids(kind).push);
        const pending = push.click();
        await push.click();
        assert.equal(env.requests.length, 1);
        assert.equal(push.disabled, true);
        const payload = JSON.parse(env.requests[0][1].body);
        assert.equal(payload.time_limit_ms, 2300);
        assert.equal(payload.test_cases[0].is_boundary, true);
        respond({ json: async () => ({ ok: true, problem_id: 5, edit_url: '/edit/5' }) });
        await pending;
        assert.equal(push.disabled, true);
        await push.click();
        assert.equal(env.requests.length, 1);
    });
    test(`${kind}: failed direct save unlocks for retry`, async () => {
        const env = boot(kind, { fetch: async () => ({ json: async () => ({ ok: false, error: '<bad>' }) }) });
        preview(kind, env, problem());
        const push = env.el(ids(kind).push);
        await push.click();
        assert.equal(push.disabled, false);
        await push.click();
        assert.equal(env.requests.length, 2);
    });
}

test.skip('retired widget preview: replaced by shared chatbot frontend suite', () => {
    const env = boot('widget');
    preview('widget', env, problem({ input_desc: 'Đầu vào ban đầu', output_desc: 'Đầu ra ban đầu' }));
    assert.equal(env.el('ap-in-desc').value, 'Đầu vào ban đầu');
    assert.equal(env.el('ap-out-desc').value, 'Đầu ra ban đầu');
    env.el('ap-in-desc').value = 'Hai số nguyên a b.';
    env.el('ap-out-desc').value = 'In a + b.';
    env.el('ap-review').click();
    const handoff = JSON.parse(env.sessionStorage.getItem('ai-prefill'));
    assert.equal(handoff.input_desc, 'Hai số nguyên a b.');
    assert.equal(handoff.output_desc, 'In a + b.');
});

test.skip('retired inline widget router: replaced by shared chatbot frontend suite', async () => {
    assert.ok(!sources.widget.includes('id="ai-mode"'));
    assert.ok(!sources.widget.includes('Chế độ chatbot'));
    for (const isStaff of [true, false]) {
        const env = boot('widget', { isStaff, fetch: async () => ({ json: async () => ({ ok: true, answer: 'OK' }) }) });
        env.el('ai-input').value = 'Tạo bài tính tổng hai số';
        await env.el('ai-send').click();
        assert.deepEqual(JSON.parse(env.requests[0][1].body), { text: 'Tạo bài tính tổng hai số' });
    }
});

*/

test('form: valid AI handoff replaces stale draft, applies empty values, persists before consuming', () => {
    const stale = { title: 'Old', slug: 'stale-slug', output_desc: 'stale output', time_limit_ms: 9900, action: 'update', _cases: [{ stdin: 'old', exp: 'old', pub: true }] };
    const prefill = problem({ slug: '', input_desc: '', output_desc: '', sample_input: '', sample_output: '', needs_review: true });
    const env = boot('form', { search: '?from_ai=1', local: { 'pf-draft-new': JSON.stringify(stale) }, session: { 'ai-prefill': JSON.stringify(prefill) } });
    const draft = env.hooks.collectDraft();
    assert.equal(draft.title, 'A title');
    assert.equal(draft.slug, '');
    assert.equal(draft.output_desc, '');
    assert.equal(draft.sample_input, '');
    assert.equal(draft.sample_output, '');
    assert.equal(Number(draft.time_limit_ms), 2300);
    assert.equal(draft.action, 'create');
    assert.equal(draft._cases[0].boundary, true);
    assert.equal(draft._cases[0].pub, false);
    assert.equal(env.sessionStorage.getItem('ai-prefill'), null);
    assert.ok(env.events.indexOf('local:set:pf-draft-new') < env.events.indexOf('session:remove:ai-prefill'));
    const stored = JSON.parse(env.localStorage.getItem('pf-draft-new'));
    assert.equal(stored._cases[0].boundary, true);
    const reloaded = boot('form', { local: { 'pf-draft-new': JSON.stringify(stored) } });
    assert.equal(reloaded.hooks.collectDraft()._cases[0].boundary, true);
    assert.equal(reloaded.hooks.collectDraft()._cases[0].pub, false);
    assert.equal(Number(reloaded.hooks.collectDraft().time_limit_ms), 2300);
});
test('form: invalid pending handoff restores old draft without consuming handoff', () => {
    const env = boot('form', { search: '?from_ai=1', local: { 'pf-draft-new': JSON.stringify({ title: 'Recovered', slug: 'recovered', action: 'update' }) }, session: { 'ai-prefill': JSON.stringify({ title: 42 }) } });
    assert.equal(env.hooks.collectDraft().title, 'Recovered');
    assert.equal(env.hooks.collectDraft().action, 'create');
    assert.notEqual(env.sessionStorage.getItem('ai-prefill'), null);
});
test('form: malformed and partial handoffs cannot suppress local draft recovery', () => {
    const env = boot('form');
    for (const invalid of [null, [], { title: 'Only a title' }, problem({ time_limit_ms: '2300' }), problem({ test_cases: [{ stdin: 'x' }] })]) {
        assert.equal(env.hooks.validPrefill(invalid), false);
    }
    assert.equal(env.hooks.validPrefill(problem({ title: '', sample_input: '', sample_output: '' })), true);
});
test('form: missing test cases use complete samples or a warned blank row', () => {
    for (const [data, stdin, exp] of [
        [problem({ test_cases: undefined }), '2 3', '5'],
        [problem({ test_cases: [], sample_output: '' }), '', ''],
        [problem({ test_cases: [], input_desc: 'No input', sample_input: '', sample_output: 'Hello' }), '', 'Hello'],
    ]) {
        const env = boot('form', { search: '?from_ai=1', session: { 'ai-prefill': JSON.stringify(data) } });
        const draft = env.hooks.collectDraft();
        assert.equal(draft._cases.length, 1);
        assert.equal(draft._cases[0].stdin, stdin);
        assert.equal(draft._cases[0].exp, exp);
        assert.equal(draft._cases[0].boundary, false);
        if (exp === '') assert.ok(env.el('pf-draft-note').textContent.includes('Chưa có test hoàn chỉnh'));
    }
});
test('form: legacy local draft boundary zero and unchecked public flags survive reload', () => {
    const draft = { title: 'Saved', 'test_boundary[0]': '0', _cases: [{ stdin: 'x', exp: 'y', pub: false }] };
    const env = boot('form', { local: { 'pf-draft-new': JSON.stringify(draft) } });
    assert.equal(env.hooks.collectDraft()._cases[0].boundary, false);
    assert.equal(env.hooks.collectDraft()._cases[0].pub, false);
});
test('form: local storage failure retains the valid AI handoff', () => {
    const env = boot('form', { search: '?from_ai=1', localFails: true, session: { 'ai-prefill': JSON.stringify(problem()) } });
    assert.equal(env.hooks.collectDraft().title, 'A title');
    assert.notEqual(env.sessionStorage.getItem('ai-prefill'), null);
    assert.ok(env.el('pf-draft-note').textContent.includes('Không lưu được'));
});
test('form: edit and server error never import or consume an AI handoff', () => {
    for (const flags of [{ isEdit: true }, { hasServerErr: true }]) {
        const env = boot('form', { ...flags, search: '?from_ai=1', session: { 'ai-prefill': JSON.stringify(problem()) } });
        assert.equal(env.hooks.collectDraft().title, '');
        assert.notEqual(env.sessionStorage.getItem('ai-prefill'), null);
    }
});
test('form: only explicit no-input descriptions waive input, never expected output', () => {
    const env = boot('form');
    const field = name => env.document.querySelector(`[name="${name}"]`);
    field('description').value = 'Print Hello';
    field('sample_output').value = 'Hello';
    assert.equal(env.hooks.validPane(2), false);
    for (const description of ['Không có dữ liệu đầu vào', 'Không cần input', 'No input']) {
        field('input_desc').value = description;
        assert.equal(env.hooks.validPane(2), true);
    }
    field('sample_output').value = '';
    assert.equal(env.hooks.validPane(2), false);
});

test('PHP: failed create remains create mode and boundary hidden values parse explicitly', t => {
    const php = process.env.PHP_BINARY || (process.platform === 'win32' ? 'C:/xampp/php/php.exe' : 'php');
    try { execFileSync(php, ['-v'], { stdio: 'pipe' }); } catch (_) { t.skip('PHP unavailable (set PHP_BINARY)'); return; }
    const prefix = sources.form.slice(5, sources.form.indexOf('$pageTitle =')).replace(/require_once[^;]+;/g, '');
    const fixture = `class Auth { static function requireRole($roles) { return ['id'=>1]; } }
class JudgeEngine { static function isSupported($lang) { return true; } }
class AIHelper { static function noInput($data) { return false; } }
function csrf_verify() {}
$_GET=[]; $_SERVER['REQUEST_METHOD']='POST';
$_POST=['action'=>'create','title'=>'A title','slug'=>'!','test_stdin'=>['a','b'],'test_expected'=>['1','2'],'test_boundary'=>['0','1']];
${prefix}
echo json_encode([$isEdit,$editing['id'],array_column($editing['cases'],'is_boundary')]);`;
    assert.deepEqual(JSON.parse(execFileSync(php, ['-r', fixture], { encoding: 'utf8' })), [false, 0, [0, 1]]);
    assert.ok(sources.form.includes('value="<?= $isEdit ? \'update\' : \'create\' ?>"'));
    assert.ok(!sources.form.includes("$pageTitle = $editing ?"));
    const caseLoop = sources.form.match(/        \$cases = \[\];([\s\S]*?)        if \(count\(\$cases\) < 1\)/)[1];
    const savedCases = `$testStdin=['a','b']; $testExpected=['1','2']; $testPublic=[]; $testBoundary=['0','1']; $noInput=false; $cases=[]; ${caseLoop} echo json_encode(array_column($cases,3));`;
    assert.deepEqual(JSON.parse(execFileSync(php, ['-r', savedCases], { encoding: 'utf8' })), [0, 1]);
    for (const [noInput, expected, count] of [['true', 'Hello', 1], ['false', 'Hello', 0], ['true', '', 0]]) {
        const noInputCase = `$testStdin=['']; $testExpected=['${expected}']; $testPublic=[0=>'1']; $testBoundary=['0']; $noInput=${noInput}; $cases=[]; ${caseLoop} echo count($cases);`;
        assert.equal(Number(execFileSync(php, ['-r', noInputCase], { encoding: 'utf8' })), count);
    }
});
