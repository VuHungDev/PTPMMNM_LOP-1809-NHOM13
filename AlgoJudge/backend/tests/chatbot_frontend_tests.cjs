// Offline only: node --test backend/tests/chatbot_frontend_tests.cjs
// No database, provider, browser download, or live HTTP requests.
const assert = require('node:assert/strict');
const { test } = require('node:test');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const file = path.resolve(__dirname, '../../frontend/assets/js/modules/chatbot.js');
const { createSSEParser, consumeSSE, safeRelativeLink, normalizeDraftData, Chatbot } = require(file);
const copy = value => JSON.parse(JSON.stringify(value));
const tick = () => new Promise(resolve => setImmediate(resolve));
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; }
function json(body, status = 200, headers = {}) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } }); }
function frame(event, data) { return 'event: ' + event + '\ndata: ' + JSON.stringify(data) + '\n\n'; }
function stream(text, byteSize = 13) {
    const bytes = new TextEncoder().encode(text);
    return new Response(new ReadableStream({ start(controller) {
        for (let i = 0; i < bytes.length; i += byteSize) controller.enqueue(bytes.slice(i, i + byteSize));
        controller.close();
    } }), { headers: { 'Content-Type': 'text/event-stream; charset=utf-8' } });
}
function answer(text = 'Cách sử dụng ứng dụng.') { return stream(frame('status', { message: 'Đang trả lời' }) + frame('delta', { text: text.slice(0, 4) }) + frame('answer', { answer: text }) + frame('done', { ok: true })); }
function envelope(changes = {}) {
    return {
        id: 'draft-1', revision: 4, status: 'review', issues: [], semantic_review: false, save_key: 'stable-save-key',
        data: { title: 'Tổng hai số', slug: 'tong-hai-so', topic: 'Cơ bản', difficulty: 'Easy', suggested_lang: 'cpp', time_limit_ms: 1200,
            description: 'Tính tổng a và b.', input_desc: 'Hai số nguyên a b.', output_desc: 'In tổng.', sample_input: '2 3', sample_output: '5',
            test_cases: [{ stdin: '2 3', expected: '5', is_public: true, is_boundary: false }, { stdin: '-1 1', expected: '0', is_public: false, is_boundary: true }],
            assumptions: ['Chọn số nguyên.'], warnings: [] }, ...changes
    };
}

// Small native-DOM double: writes to innerHTML are forbidden, just as in the app.
class Element {
    constructor(tag, ownerDocument) {
        this.tagName = tag.toUpperCase(); this.ownerDocument = ownerDocument; this.children = []; this.parentNode = null;
        this.attrs = {}; this.dataset = {}; this.listeners = {}; this.className = ''; this._text = ''; this.value = ''; this.hidden = false;
        this.checked = false; this.disabled = false; this.style = {}; this.scrollHeight = 500; this.scrollTop = 300; this.clientHeight = 300;
        this.classList = { contains: name => this.className.split(/\s+/).includes(name) };
    }
    set innerHTML(_) { throw new Error('HTML rendering is forbidden'); }
    get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
    set textContent(value) { this._text = String(value); this.children = []; }
    appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
    replaceChildren(...children) { this._text = ''; this.children = []; children.forEach(child => this.appendChild(child)); }
    remove() { if (this.parentNode) this.parentNode.children = this.parentNode.children.filter(child => child !== this); }
    setAttribute(key, value) { this.attrs[key] = String(value); if (key === 'class') this.className = value; }
    removeAttribute(key) { delete this.attrs[key]; }
    getAttribute(key) { return this.attrs[key] ?? null; }
    addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); }
    emit(name, extra = {}) { const event = { type: name, target: this, currentTarget: this, preventDefault() {}, ...extra }; return (this.listeners[name] || []).map(fn => fn(event)); }
    focus() { this.ownerDocument.activeElement = this; }
    matches(selector) {
        const attr = selector.match(/^\[([^=\]]+)(?:="([^"]*)")?\]$/);
        if (attr) {
            const name = attr[1];
            const value = name.startsWith('data-') ? this.dataset[name.slice(5).replace(/-([a-z])/g, (_, char) => char.toUpperCase())] : this.attrs[name];
            return value !== undefined && (attr[2] === undefined || value === attr[2]);
        }
        if (selector === 'meta[name="csrf-token"]') return this.tagName === 'META' && this.attrs.name === 'csrf-token';
        if (selector.startsWith('.')) return this.classList.contains(selector.slice(1));
        return this.tagName.toLowerCase() === selector;
    }
    querySelectorAll(selector) { return this.children.flatMap(child => [child, ...child.querySelectorAll('*')]).filter(child => selector === '*' || child.matches(selector)); }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
}
class Document extends Element {
    constructor() { super('document', null); this.ownerDocument = this; this.readyState = 'complete'; }
    createElement(tag) { return new Element(tag, this); }
}
function boot(options = {}) {
    const doc = new Document();
    const meta = doc.createElement('meta'); meta.attrs.name = 'csrf-token'; meta.content = 'csrf-fixture'; doc.appendChild(meta);
    const root = doc.createElement('div'); root.className = 'aj-chatbot aj-chatbot--' + (options.floating ? 'floating' : 'page');
    root.dataset.staff = options.student ? '0' : '1'; root.dataset.baseUrl = '/AlgoJudge'; doc.appendChild(root);
    const common = ['panel', 'launcher', 'close', 'scroll', 'messages', 'notice', 'notice-text', 'errors', 'status', 'input', 'send', 'composer', 'stop', 'restore', 'recovery', 'restore-confirm', 'restore-keep', 'backups', 'backup-list'];
    const staff = ['staging', 'staging-fields', 'staging-note', 'draft', 'draft-revision', 'draft-status', 'issues', 'draft-form', 'draft-fields', 'draft-editor', 'review-area', 'reviewed', 'sync', 'review', 'save', 'cancel', 'cancel-confirm', 'cancel-yes', 'cancel-no', 'saved'];
    const refs = {};
    [...common, ...(options.student ? [] : staff)].forEach(name => {
        const el = doc.createElement(name === 'input' ? 'textarea' : 'div');
        el.dataset['chatbot' + name.split('-').map(word => word[0].toUpperCase() + word.slice(1)).join('')] = '';
        root.appendChild(el); refs[name] = el;
    });
    refs.panel.hidden = !!options.floating;
    const calls = [], queue = [];
    async function fetch(url, init) {
        const call = { url, ...init, data: init.body ? JSON.parse(init.body) : undefined };
        calls.push(call);
        if (!queue.length) throw new Error('Unexpected fetch: ' + url);
        const reply = queue.shift();
        return typeof reply === 'function' ? reply(call) : reply;
    }
    const bot = new Chatbot(root, { fetch });
    return { bot, refs, root, doc, calls, queue };
}
function edit(bot, key, value) { const control = bot.controls.get(key); assert.ok(control, key + ' is editable'); control.input.value = value; control.input.emit('input'); }

// Framing and byte decoding.
test('SSE parser handles every split boundary, BOM, comments, CRLF, CR, multiline and EOF', () => {
    const source = '﻿: ping\r\nevent: status\r\ndata: {"message":\r\ndata: "Đang soạn"}\r\n\r\nevent: delta\rdata: {"text":"X"}\r\revent: done\ndata: {"ok":true}';
    for (let cut = 0; cut <= source.length; cut++) {
        const events = []; const parser = createSSEParser(event => events.push(event));
        parser.push(source.slice(0, cut)); parser.push(source.slice(cut)); parser.finish();
        assert.deepEqual(events.map(event => [event.event, JSON.parse(event.data)]), [['status', { message: 'Đang soạn' }], ['delta', { text: 'X' }], ['done', { ok: true }]]);
    }
});
test('native SSE stream decodes one-byte UTF-8 chunks without replacement characters', async () => {
    const events = [];
    const result = await consumeSSE(stream(frame('status', { message: 'Đang kiểm tra đề' }) + frame('delta', { text: 'Tiếng Việt 😀 <br>\n2 3' }) + frame('done', { ok: true }), 1), (name, data) => events.push([name, data]));
    assert.equal(result.ok, true);
    assert.equal(events[1][1].text, 'Tiếng Việt 😀 <br>\n2 3');
});
test('done without EOF completes and cancels the reader; future events are ignored', async () => {
    let cancelled = false;
    const response = new Response(new ReadableStream({ start(controller) { controller.enqueue(new TextEncoder().encode(frame('done', { ok: true }) + frame('delta', { text: 'late' }))); }, cancel() { cancelled = true; } }));
    const seen = [];
    await consumeSSE(response, name => seen.push(name));
    assert.deepEqual(seen, ['done']); assert.equal(cancelled, true);
});
test('SSE rejects EOF without done, invalid JSON and invalid done payload', async () => {
    await assert.rejects(consumeSSE(stream(frame('delta', { text: 'half' })), () => {}), error => error.code === 'stream_incomplete');
    await assert.rejects(consumeSSE(stream('event: answer\ndata: {broken}\n\n'), () => {}), error => error.code === 'invalid_stream_json');
    await assert.rejects(consumeSSE(stream(frame('done', {})), () => {}), error => error.code === 'invalid_stream_event');
});
test('SSE dispatches final event without newline and allows a failed done', async () => {
    const seen = [];
    const result = await consumeSSE(stream('event: unknown\ndata: not-json\n\nevent: done\ndata: {"ok":false}'), (name, value) => seen.push([name, value]));
    assert.equal(result.ok, false); assert.deepEqual(seen, [['done', { ok: false }]]);
});

test('verified links reject external, encoded, traversal, protocol and unsafe paths', () => {
    for (const value of ['javascript:alert(1)', 'https://example.com/AlgoJudge/frontend/index.php', '//host/AlgoJudge/frontend/index.php', '/frontend/index.php', '/AlgoJudge/backend/api/ai_chat.php', '/AlgoJudge/frontend/../auth.php', '/AlgoJudge/frontend/%2e%2e/index.php', '/AlgoJudge/frontend/%2Findex.php', '/AlgoJudge/frontend/a\\b.php', '/AlgoJudge/frontend/a.php\n', '/AlgoJudge/frontend/a.php"onclick="bad', '/AlgoJudge/frontend/index.php/extra', '/AlgoJudge/frontend/a.html']) assert.equal(safeRelativeLink(value), null, value);
    for (const value of ['/AlgoJudge/frontend/index.php', '/AlgoJudge/frontend/instructor/problem_form.php?edit=8', '/AlgoJudge/frontend/workspace.php?slug=sum#samples']) assert.equal(safeRelativeLink(value), value);
});
test('normalization preserves literal data, language, time, public and boundary flags', () => {
    const data = envelope().data; data.test_cases[0].stdin = '# <br> * &lt;\nYES\n';
    assert.deepEqual(normalizeDraftData(data), data);
});
test('external content is rendered as text only and links are verified', () => {
    const { bot, refs } = boot(); const literal = '<img src=x onerror=alert(1)> **not HTML**';
    const message = bot.addMessage('assistant', literal, [{ label: literal, url: 'javascript:alert(1)' }, { label: '<script>x</script>', url: '/AlgoJudge/frontend/index.php' }]);
    assert.equal(message.content.textContent, literal);
    const anchors = refs.messages.querySelectorAll('a'); assert.equal(anchors.length, 1); assert.equal(anchors[0].textContent, '<script>x</script>');
});
test('students see app-only greeting and never render staff draft or staging', () => {
    const { bot, refs } = boot({ student: true });
    assert.match(refs.messages.textContent, /không cung cấp lời giải\/code/);
    bot.acceptDraft(envelope()); bot.stageField({ field: 'test_cases', value: envelope().data.test_cases });
    assert.equal(bot.state.draft, null); assert.equal(refs.draft, undefined); assert.equal(bot.staged.size, 0);
});
test('every canonical field is editable, including test flags, assumptions and warnings', () => {
    const { bot, root } = boot(); bot.acceptDraft(envelope());
    for (const key of ['title', 'slug', 'topic', 'difficulty', 'suggested_lang', 'time_limit_ms', 'description', 'input_desc', 'output_desc', 'sample_input', 'sample_output', 'test_cases.0.stdin', 'test_cases.0.expected', 'assumptions.0']) assert.ok(bot.controls.has(key), key);
    const flags = root.querySelectorAll('[data-draft-field]');
    const boundary = flags.find(input => input.dataset.draftField === 'test_cases.0.is_boundary');
    boundary.checked = true; boundary.emit('change');
    assert.equal(bot.state.local.test_cases[0].is_boundary, true); assert.equal(bot.state.dirty, true);
});
test('test rows can be added and deleted without losing flags or samples', () => {
    const { bot, refs } = boot(); bot.acceptDraft(envelope());
    refs['draft-editor'].querySelectorAll('button').find(button => button.textContent === '+ Thêm test').emit('click');
    assert.equal(bot.state.local.test_cases.length, 3);
    edit(bot, 'test_cases.2.stdin', '0 0'); edit(bot, 'test_cases.2.expected', '0');
    refs['draft-editor'].querySelectorAll('button').find(button => button.textContent === 'Xóa test 1').emit('click');
    assert.equal(bot.state.local.test_cases.length, 2); assert.equal(bot.state.local.test_cases[0].is_boundary, true); assert.equal(bot.state.local.sample_input, '2 3');
});
test('dirty draft sync completes before follow-up; draft id/revision travels with streamed chat', async () => {
    const { bot, refs, queue, calls } = boot(); bot.acceptDraft(envelope());
    edit(bot, 'title', 'Tên thầy/cô đã sửa');
    const synced = envelope({ revision: 5, semantic_review: true, data: copy(bot.state.local) });
    queue.push(json({ ok: true, draft: synced }), answer('Đã hướng dẫn.'));
    refs.input.value = 'Cách xem bài nộp?';
    assert.equal(await bot.send(), true);
    assert.equal(calls.length, 2); assert.ok(calls[0].url.endsWith('/ai_draft.php')); assert.equal(calls[0].data.action, 'update');
    assert.equal(calls[0].data.data.title, 'Tên thầy/cô đã sửa');
    assert.deepEqual(calls[1].data, { text: 'Cách xem bài nộp?', draft_id: 'draft-1', revision: 5 });
    assert.equal(calls[1].credentials, 'same-origin'); assert.equal(calls[1].headers['X-CSRF-Token'], 'csrf-fixture'); assert.equal(calls[1].headers.Accept, 'text/event-stream');
    assert.equal(refs.draft.hidden, false); assert.equal(bot.state.local.title, 'Tên thầy/cô đã sửa'); assert.equal(refs.input.value, '');
});
test('new query keeps preview visible; streamed fields stage separately until draft envelope', async () => {
    const { bot, refs, queue } = boot(); bot.acceptDraft(envelope());
    let controller;
    queue.push(new Response(new ReadableStream({ start(value) { controller = value; } }), { headers: { 'Content-Type': 'text/event-stream' } }));
    refs.input.value = 'Bổ sung test'; const running = bot.send(); await tick();
    assert.equal(refs.draft.hidden, false); assert.equal(refs['draft-fields'].disabled, true); assert.equal(refs.send.disabled, true);
    controller.enqueue(new TextEncoder().encode(frame('field', { field: 'title', value: 'Tên đang sinh' }))); await tick();
    assert.equal(refs.staging.hidden, false); assert.match(refs['staging-fields'].textContent, /Tên đang sinh/); assert.equal(bot.controls.get('title').input.value, 'Tổng hai số');
    const revised = envelope({ revision: 5, data: { ...envelope().data, title: 'Tên được xác nhận' } });
    controller.enqueue(new TextEncoder().encode(frame('draft', { draft: revised }) + frame('done', { ok: true }))); controller.close();
    assert.equal(await running, true); assert.equal(bot.controls.get('title').input.value, 'Tên được xác nhận'); assert.equal(refs.staging.hidden, true); assert.equal(refs['draft-fields'].disabled, false);
});
test('sync HTTP409 preserves every dirty input and exposes an on-screen copy; no chat follows', async () => {
    const { bot, refs, queue, calls } = boot(); bot.acceptDraft(envelope());
    edit(bot, 'description', 'Sửa quan trọng <b>literal</b>'); edit(bot, 'test_cases.1.expected', '99'); refs.input.value = 'Giữ yêu cầu chưa gửi';
    queue.push(json({ ok: false, code: 'revision_conflict', error: 'Phiên bản đã thay đổi.', draft: envelope({ revision: 20 }) }, 409));
    assert.equal(await bot.send(), false); assert.equal(calls.length, 1); assert.equal(bot.state.conflict, true);
    assert.equal(bot.controls.get('description').input.value, 'Sửa quan trọng <b>literal</b>'); assert.equal(bot.controls.get('test_cases.1.expected').input.value, '99');
    assert.equal(refs.input.value, 'Giữ yêu cầu chưa gửi'); assert.equal(refs.backups.hidden, false);
    assert.match(refs['backup-list'].querySelector('textarea').value, /Sửa quan trọng/);
    assert.equal(refs.send.disabled, true); assert.equal(refs.save.disabled, true);
});
test('explicit recovery is two-step for dirty data and preserves copy after loading latest', async () => {
    const { bot, refs, queue, calls } = boot(); bot.acceptDraft(envelope()); edit(bot, 'sample_output', '123');
    bot.requestRestore(); assert.equal(calls.length, 0); assert.equal(refs.recovery.hidden, false);
    queue.push(json({ ok: true, messages: [{ role: 'assistant', text: 'Lịch sử máy chủ' }], draft: envelope({ revision: 8 }), processing: false }));
    await bot.restore(true);
    assert.equal(bot.state.draft.revision, 8); assert.equal(bot.state.local.sample_output, '5'); assert.equal(refs.backups.hidden, false);
    assert.match(refs['backup-list'].querySelector('textarea').value, /123/); assert.match(refs.messages.textContent, /Lịch sử máy chủ/);
});
test('semantic changes gate save and explicit checked review syncs before review', async () => {
    const { bot, refs, queue, calls } = boot(); bot.acceptDraft(envelope()); edit(bot, 'description', 'Mô tả mới');
    assert.equal(refs.save.disabled, true);
    refs.reviewed.checked = true; refs.reviewed.emit('change');
    const updated = envelope({ revision: 5, semantic_review: true, data: copy(bot.state.local) });
    queue.push(json({ ok: true, draft: updated }), json({ ok: true, draft: { ...updated, revision: 6, semantic_review: false } }));
    assert.equal(await bot.review(), true);
    assert.deepEqual(calls.map(call => call.data.action), ['update', 'review']); assert.equal(calls[1].data.revision, 5); assert.equal(bot.state.draft.revision, 6); assert.equal(refs.save.disabled, false);
});
test('server normalization after sync clears acknowledgement and requires fresh review', async () => {
    const { bot, refs, queue, calls } = boot(); bot.acceptDraft(envelope()); edit(bot, 'description', 'Đổi yêu cầu'); refs.reviewed.checked = true;
    const data = copy(bot.state.local); data.description = 'Đổi yêu cầu (đã chuẩn hóa)';
    queue.push(json({ ok: true, draft: envelope({ revision: 5, semantic_review: true, data }) }));
    await bot.review(); assert.equal(calls.length, 1); assert.equal(refs.reviewed.checked, false); assert.match(refs['notice-text'].textContent, /chuẩn hóa/); assert.equal(refs.save.disabled, true);
});
test('saying OK never saves; explicit save uses revision and same server idempotency key on retry', async () => {
    const { bot, refs, queue, calls } = boot(); bot.acceptDraft(envelope()); refs.input.value = 'OK'; queue.push(answer('Bản nháp vẫn chờ bấm nút Thêm bài.'));
    await bot.send(); assert.equal(calls.length, 1); assert.ok(calls[0].url.endsWith('/ai_chat.php'));
    queue.push(() => Promise.reject(new TypeError('offline')));
    assert.equal(await bot.save(), false); assert.equal(bot.state.draft.status, 'review'); assert.equal(bot.state.local.title, 'Tổng hai số');
    const saved = envelope({ status: 'saved', revision: 5, problem_id: 8, edit_url: '/AlgoJudge/frontend/instructor/problem_form.php?edit=8' });
    queue.push(json({ ok: true, draft: saved, problem_id: 8, edit_url: saved.edit_url }));
    assert.equal(await bot.save(), true);
    assert.deepEqual(calls[1].data, { draft_id: 'draft-1', revision: 4, idempotency_key: 'stable-save-key' }); assert.deepEqual(calls[2].data, calls[1].data);
    assert.equal(bot.state.draft.status, 'saved'); assert.equal(refs.save.disabled, true); assert.match(refs.messages.textContent, /Đã tạo bài #8/);
});
test('pending nonsemantic edits sync before explicit save', async () => {
    const { bot, queue, calls } = boot(); bot.acceptDraft(envelope()); edit(bot, 'slug', 'tong-da-sua');
    const updated = envelope({ revision: 5, save_key: 'updated-key', data: copy(bot.state.local) });
    queue.push(json({ ok: true, draft: updated }), json({ ok: true, problem_id: 17, draft: { ...updated, revision: 6, status: 'saved', problem_id: 17 } }));
    await bot.save(); assert.equal(calls[0].data.action, 'update'); assert.equal(calls[1].data.revision, 5); assert.equal(calls[1].data.idempotency_key, 'updated-key');
});
test('save validation errors preserve data and mark precise fields', async () => {
    const { bot, refs, queue } = boot(); bot.acceptDraft(envelope());
    queue.push(json({ ok: false, error: 'Test sai.', code: 'validation', fields: { 'test_cases.1.expected': 'Output chưa đúng' } }, 422));
    await bot.save(); assert.equal(bot.state.draft.status, 'review'); assert.equal(bot.controls.get('test_cases.1.expected').input.value, '0');
    assert.equal(bot.controls.get('test_cases.1.expected').input.getAttribute('aria-invalid'), 'true'); assert.match(refs.errors.textContent, /Output chưa đúng/);
});
test('one operation lock prevents concurrent send, sync, review and save', async () => {
    const { bot, refs, queue, calls } = boot(); bot.acceptDraft(envelope());
    const gate = deferred(); queue.push(() => gate.promise); refs.input.value = 'Hỏi ứng dụng'; const sending = bot.send(); await tick();
    assert.equal(await bot.save(), false); assert.equal(await bot.run('sync', () => bot.syncDraft()), false); assert.equal(await bot.send(), false);
    assert.equal(calls.length, 1); assert.equal(refs['draft-fields'].disabled, true); assert.equal(refs.send.disabled, true);
    gate.resolve(answer()); await sending; assert.equal(refs.send.disabled, false); assert.equal(refs['draft-fields'].disabled, false);
});
test('stream failure keeps input and existing draft, exposes recovery, does not auto-fetch', async () => {
    const { bot, refs, queue, calls } = boot(); bot.acceptDraft(envelope()); refs.input.value = 'Sửa test';
    queue.push(stream(frame('delta', { text: 'Phản hồi một phần' }) + frame('field', { field: 'sample_output', value: '99' })));
    assert.equal(await bot.send(), false); assert.equal(calls.length, 1); assert.equal(bot.state.local.sample_output, '5'); assert.equal(refs.input.value, 'Sửa test');
    assert.equal(bot.state.recoveryRequired, true); assert.equal(refs.send.disabled, true); assert.equal(refs.restore.disabled, false); assert.match(refs['staging-note'].textContent, /gián đoạn/); assert.match(refs.messages.textContent, /chưa hoàn tất/);
});
test('stop wait aborts native stream and posts stop for students without claiming provider cancellation', async () => {
    const { bot, refs, queue, calls } = boot({ student: true });
    queue.push(call => new Response(new ReadableStream({ start(controller) {
        call.signal.addEventListener('abort', () => controller.error(new DOMException('Aborted', 'AbortError')));
        controller.enqueue(new TextEncoder().encode(frame('status', { message: 'Đang chờ provider' })));
    } }), { headers: { 'Content-Type': 'text/event-stream' } }), json({ ok: true }));
    refs.input.value = 'Xem bài nộp'; const running = bot.send(); await tick(); assert.equal(refs.stop.hidden, false);
    await bot.stopWaiting(); await running;
    assert.equal(calls[0].signal.aborted, true); assert.deepEqual(calls[1].data, { action: 'stop' }); assert.equal(bot.state.recoveryRequired, true);
    assert.match(refs['notice-text'].textContent, /cố gắng tối đa/); assert.match(refs['notice-text'].textContent, /có thể không dừng ngay/); assert.equal(refs.input.value, 'Xem bài nộp');
});
test('stop is not offered during pre-chat sync; stop response must finish before new actions', async () => {
    const { bot, refs, queue, calls } = boot(); bot.acceptDraft(envelope()); edit(bot, 'slug', 'edited-slug');
    const sync = deferred(), stop = deferred();
    queue.push(() => sync.promise, call => new Response(new ReadableStream({ start(controller) { call.signal.addEventListener('abort', () => controller.error(new DOMException('Abort', 'AbortError'))); } }), { headers: { 'Content-Type': 'text/event-stream' } }), () => stop.promise);
    refs.input.value = 'Hướng dẫn'; const running = bot.send(); await tick(); assert.equal(refs.stop.hidden, true); await bot.stopWaiting(); assert.equal(calls.length, 1);
    sync.resolve(json({ ok: true, draft: envelope({ revision: 5, data: copy(bot.state.local) }) })); await tick();
    const stopping = bot.stopWaiting(); await tick(); assert.equal(refs.send.disabled, true); assert.equal(refs.restore.disabled, true); assert.equal(await bot.save(), false);
    stop.resolve(json({ ok: true })); await stopping; await running; assert.equal(refs.restore.disabled, false); assert.equal(bot.state.operation, '');
});
test('draft cancel uses inline two-step and never creates a problem', async () => {
    const { bot, refs, queue, calls } = boot(); bot.acceptDraft(envelope());
    await bot.cancelDraft(); assert.equal(calls.length, 0);
    refs.cancel.emit('click'); assert.equal(refs['cancel-confirm'].hidden, false);
    queue.push(json({ ok: true, draft: envelope({ revision: 5, status: 'cancelled' }) }));
    await bot.cancelDraft(); assert.deepEqual(calls[0].data, { action: 'cancel', draft_id: 'draft-1', revision: 4 }); assert.equal(refs.save.disabled, true); assert.equal(bot.state.draft.status, 'cancelled');
});
test('restore gets same-origin no-store state and handles remote processing', async () => {
    const { bot, refs, queue, calls } = boot({ student: true });
    queue.push(json({ ok: true, messages: [{ role: 'user', text: 'Riêng tư' }], draft: envelope(), processing: true }));
    await bot.restore(false); assert.equal(calls[0].method, 'GET'); assert.equal(calls[0].credentials, 'same-origin'); assert.equal(calls[0].cache, 'no-store');
    assert.match(refs.messages.textContent, /Riêng tư/); assert.equal(bot.state.draft, null); assert.equal(refs.send.disabled, true); assert.equal(refs.stop.hidden, false);
});
test('429 and setup failures give distinct clear messages without discarding edits', async () => {
    const { bot, refs, queue } = boot(); refs.input.value = 'Giữ câu hỏi';
    queue.push(json({ ok: false, code: 'chatbot_setup', error: 'Setup needed' }, 503)); await bot.restore(false); assert.match(refs['notice-text'].textContent, /cần cài bản cập nhật/); assert.equal(refs.input.value, 'Giữ câu hỏi');
    queue.push(json({ ok: true, messages: [], draft: null, processing: false })); await bot.restore(false);
    queue.push(json({ ok: false, code: 'rate_limited', error: 'Quá nhiều yêu cầu' }, 429, { 'Retry-After': '30' }));
    await bot.send(); assert.match(refs['notice-text'].textContent, /giới hạn yêu cầu/); assert.match(refs['notice-text'].textContent, /30 giây/); assert.equal(refs.input.value, 'Giữ câu hỏi');
});
test('invalid restore is transactional and never overwrites dirty inputs', async () => {
    const { bot, refs, queue } = boot(); bot.acceptDraft(envelope()); edit(bot, 'title', 'Tên chưa đồng bộ'); bot.requestRestore();
    queue.push(json({ ok: true, messages: [], draft: { id: 'broken' }, processing: false }));
    assert.equal(await bot.restore(true), false); assert.equal(bot.controls.get('title').input.value, 'Tên chưa đồng bộ'); assert.equal(bot.state.dirty, true); assert.equal(refs.backups.hidden, false);
});
test('streamed auth expiry clears sensitive UI and ignores any later draft event', async () => {
    const { bot, refs, queue } = boot(); bot.acceptDraft(envelope()); bot.preserveCopy(); refs.input.value = 'Câu hỏi riêng tư';
    queue.push(stream(frame('delta', { text: 'Đang trả lời' }) + frame('error', { error: 'Hết phiên.', code: 'auth_expired', status: 401 }) + frame('draft', { draft: envelope({ revision: 99 }) }) + frame('done', { ok: false })));
    assert.equal(await bot.send(), false);
    assert.equal(bot.state.authLost, true); assert.equal(bot.state.draft, null); assert.equal(refs.messages.textContent, '');
    assert.equal(refs['draft-editor'].children.length, 0); assert.equal(refs['backup-list'].children.length, 0); assert.equal(refs.input.value, '');
    assert.equal(refs.send.disabled, true); assert.match(refs['notice-text'].textContent, /đăng nhập/);
});
test('SSE validation string arrays are shown without hiding the existing draft', async () => {
    const { bot, refs, queue } = boot(); bot.acceptDraft(envelope()); refs.input.value = 'Sửa bản nháp';
    queue.push(stream(frame('error', { error: 'Cần sửa.', code: 'draft_validation', status: 422, fields: ['Output ví dụ mâu thuẫn với test cùng input.', 'Test 2 chưa có dữ liệu.'] }) + frame('done', { ok: false })));
    assert.equal(await bot.send(), false); assert.equal(refs.draft.hidden, false); assert.equal(bot.state.draft.revision, 4);
    assert.match(refs.errors.textContent, /Output ví dụ mâu thuẫn/); assert.match(refs.errors.textContent, /Test 2/); assert.equal(refs.input.value, 'Sửa bản nháp');
});
test('SSE draft_conflict protects edits and offers explicit recovery', async () => {
    const { bot, refs, queue } = boot(); bot.acceptDraft(envelope()); refs.input.value = 'Hỏi tiếp';
    queue.push(stream(frame('error', { error: 'Bản nháp khác phiên bản.', code: 'draft_conflict', status: 409 }) + frame('done', { ok: false })));
    assert.equal(await bot.send(), false); assert.equal(bot.state.conflict, true); assert.equal(bot.state.draft.revision, 4);
    assert.equal(refs.backups.hidden, false); assert.match(refs['notice-text'].textContent, /Khôi phục trạng thái/);
});
test('assets share a component and have no legacy actions, storage history or timeout', () => {
    const root = path.resolve(__dirname, '../..');
    const widget = fs.readFileSync(path.join(root, 'frontend/partials/ai_widget.php'), 'utf8');
    const page = fs.readFileSync(path.join(root, 'frontend/instructor/ai_chat.php'), 'utf8');
    const component = fs.readFileSync(path.join(root, 'frontend/partials/chatbot_panel.php'), 'utf8');
    const source = fs.readFileSync(file, 'utf8');
    assert.match(widget, /chatbot_panel\.php/); assert.match(page, /chatbot_panel\.php/); assert.match(page, /\$hideAIWidget = true/);
    for (const text of [source, widget, page, component]) assert.doesNotMatch(text, /innerHTML|confirm_token|ai-prefill|ai_parse_problem\.php|localStorage|sessionStorage|setTimeout|AbortSignal\.timeout/);
    assert.match(source, /TextDecoder/); assert.match(component, /data-chatbot-staging/); assert.match(component, /Kiểm tra và duyệt chỉnh sửa/);
});
test('browser VM mounts the shared component exactly once', () => {
    const fixture = boot({ floating: true }); const document = fixture.doc; fixture.root.dataset.chatbot = '';
    const context = vm.createContext({ document, console, URL, TextDecoder, AbortController, fetch: async () => { throw new Error('Floating state is lazy until opened'); } });
    const source = fs.readFileSync(file, 'utf8'); vm.runInContext(source, context); const first = fixture.root.chatbot;
    assert.ok(first); assert.equal(first.state.restored, false); vm.runInContext(source, context); assert.equal(fixture.root.chatbot, first);
});
