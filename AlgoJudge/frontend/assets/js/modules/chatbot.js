/* Native streamed chatbot. No client-side history, HTML rendering, or provider timeout. */
(function (global, factory) {
    'use strict';
    if (typeof module === 'object' && module.exports) {
        module.exports = factory(global);
    } else if (!global.AlgoJudgeChatbot) {
        const api = factory(global);
        global.AlgoJudgeChatbot = api;
        const boot = () => api.mount(global.document);
        if (global.document.readyState === 'loading') global.document.addEventListener('DOMContentLoaded', boot);
        else boot();
    }
})(typeof globalThis !== 'undefined' ? globalThis : this, function (global) {
    'use strict';

    // SSE lines may be split at any byte, CR/LF, field, or JSON boundary. The decoder
    // below handles UTF-8; this parser handles SSE framing, including multiline data.
    function createSSEParser(onEvent) {
        let buffer = '', event = '', data = [], first = true, ended = false;
        function dispatch() {
            if (data.length) onEvent({ event: event || 'message', data: data.join('\n') });
            event = '';
            data = [];
        }
        function line(value) {
            if (value === '') { dispatch(); return; }
            if (value[0] === ':') return;
            const colon = value.indexOf(':');
            const field = colon === -1 ? value : value.slice(0, colon);
            let content = colon === -1 ? '' : value.slice(colon + 1);
            if (content[0] === ' ') content = content.slice(1);
            if (field === 'event') event = content;
            if (field === 'data') data.push(content);
        }
        function push(chunk, final = false) {
            if (ended) throw new Error('SSE parser already ended.');
            let text = String(chunk || '');
            if (first && text.length) { text = text.replace(/^﻿/, ''); first = false; }
            buffer += text;
            let start = 0;
            for (let i = 0; i < buffer.length; i++) {
                const char = buffer[i];
                if (char !== '\r' && char !== '\n') continue;
                if (char === '\r' && i === buffer.length - 1 && !final) break;
                line(buffer.slice(start, i));
                if (char === '\r' && buffer[i + 1] === '\n') i++;
                start = i + 1;
            }
            buffer = buffer.slice(start);
            if (final) {
                if (buffer.length) line(buffer);
                buffer = '';
                dispatch();
                ended = true;
            }
        }
        return { push, finish: () => push('', true) };
    }

    class ChatbotError extends Error {
        constructor(message, code = '', status = 0, fields = null, retryAfter = '') {
            super(message);
            this.name = 'ChatbotError';
            this.code = code;
            this.status = status;
            this.fields = fields;
            this.retryAfter = retryAfter;
        }
    }

    async function consumeSSE(response, onEvent) {
        if (!response.body || typeof response.body.getReader !== 'function') {
            throw new ChatbotError('Trình duyệt hoặc máy chủ không cung cấp luồng phản hồi.', 'stream_unavailable');
        }
        const reader = response.body.getReader();
        const decoder = new TextDecoder('utf-8');
        let done = null;
        const known = new Set(['status', 'delta', 'field', 'draft', 'answer', 'error', 'done']);
        const parser = createSSEParser(({ event, data }) => {
            if (done !== null || !known.has(event)) return;
            let payload;
            try { payload = JSON.parse(data); }
            catch (_) { throw new ChatbotError('Máy chủ trả dữ liệu luồng không hợp lệ.', 'invalid_stream_json'); }
            if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
                throw new ChatbotError('Sự kiện luồng không đúng định dạng.', 'invalid_stream_event');
            }
            if (event === 'done') {
                if (typeof payload.ok !== 'boolean') throw new ChatbotError('Thiếu trạng thái kết thúc luồng.', 'invalid_stream_event');
                done = payload;
            }
            onEvent(event, payload);
        });
        try {
            while (done === null) {
                const chunk = await reader.read();
                if (chunk.done) {
                    parser.push(decoder.decode(), true);
                    break;
                }
                parser.push(decoder.decode(chunk.value, { stream: true }));
            }
            if (done === null) throw new ChatbotError('Kết nối kết thúc trước tín hiệu hoàn tất. Nội dung nhận được có thể chưa đầy đủ.', 'stream_incomplete');
            return done;
        } finally {
            // Release the response even when JSON, framing, or the caller fails.
            try { await reader.cancel(); } catch (_) { /* connection may already be closed */ }
            reader.releaseLock();
        }
    }

    // Only app-local PHP screens are navigable. URL normalization must not turn
    // encoded separators, dot segments, backslashes, or a network URL into a link.
    function safeRelativeLink(value) {
        if (typeof value !== 'string' || value !== value.trim() || /[\s\\<>"'\u0000-\u001f\u007f]/.test(value)) return null;
        const path = value.split(/[?#]/, 1)[0];
        if (!/^\/AlgoJudge\/frontend\/(?:[A-Za-z0-9_-]+\/)*[A-Za-z0-9_-]+\.php$/.test(path)) return null;
        try {
            const parsed = new URL(value, 'https://algojudge.invalid');
            if (parsed.origin !== 'https://algojudge.invalid' || parsed.pathname !== path) return null;
            return value;
        } catch (_) { return null; }
    }

    const stringFields = ['title', 'slug', 'topic', 'difficulty', 'suggested_lang', 'description', 'input_desc', 'output_desc', 'sample_input', 'sample_output'];
    // Match the server's semantic-review boundary; metadata-only changes can
    // synchronize without forcing another sample/test acknowledgement.
    const semanticFields = ['description', 'input_desc', 'output_desc', 'sample_input', 'sample_output', 'test_cases'];
    const labels = {
        title: 'Tiêu đề', slug: 'Slug', topic: 'Chủ đề', difficulty: 'Độ khó', suggested_lang: 'Ngôn ngữ gợi ý',
        time_limit_ms: 'Giới hạn chạy (ms)', description: 'Mô tả và ràng buộc', input_desc: 'Mô tả đầu vào', output_desc: 'Mô tả đầu ra',
        sample_input: 'Ví dụ input', sample_output: 'Ví dụ output', test_cases: 'Test case', assumptions: 'Giả định bổ sung', warnings: 'Cảnh báo'
    };
    const asText = value => typeof value === 'string' ? value : '';
    const flag = value => value === true || value === 1 || value === '1';
    const clone = value => JSON.parse(JSON.stringify(value));
    function normalizeDraftData(data) {
        const source = data && typeof data === 'object' ? data : {};
        const result = {};
        stringFields.forEach(field => { result[field] = asText(source[field]); });
        result.time_limit_ms = typeof source.time_limit_ms === 'number' ? source.time_limit_ms : 1000;
        result.test_cases = Array.isArray(source.test_cases) ? source.test_cases.map(c => ({
            stdin: asText(c && c.stdin), expected: asText(c && c.expected),
            is_public: flag(c && c.is_public), is_boundary: flag(c && c.is_boundary)
        })) : [];
        result.assumptions = Array.isArray(source.assumptions) ? source.assumptions.filter(v => typeof v === 'string') : [];
        result.warnings = Array.isArray(source.warnings) ? source.warnings.filter(v => typeof v === 'string') : [];
        return result;
    }
    function validateEnvelope(value) {
        if (!value || typeof value !== 'object' || typeof value.id !== 'string' || !value.id ||
            !Number.isSafeInteger(value.revision) || value.revision < 0 ||
            !['review', 'saved', 'cancelled'].includes(value.status) || !value.data || typeof value.data !== 'object' ||
            typeof value.semantic_review !== 'boolean' || typeof value.save_key !== 'string') {
            throw new ChatbotError('Bản nháp máy chủ không đúng định dạng. Bản đang nhập vẫn được giữ.', 'invalid_draft');
        }
        return { ...value, data: normalizeDraftData(value.data), issues: Array.isArray(value.issues) ? value.issues.filter(v => typeof v === 'string') : [] };
    }
    function fieldErrors(fields, prefix = '') {
        if (!fields) return [];
        if (typeof fields === 'string') return [{ field: prefix, message: fields }];
        if (Array.isArray(fields)) return fields.flatMap((item, index) => {
            if (typeof item === 'string') return [{ field: prefix, message: item }];
            return fieldErrors(item, prefix ? prefix + '.' + index : '');
        });
        if (typeof fields === 'object') return Object.entries(fields).flatMap(([key, value]) => fieldErrors(value, prefix ? prefix + '.' + key : key));
        return [];
    }

    class Chatbot {
        constructor(root, options = {}) {
            this.root = root;
            this.doc = root.ownerDocument;
            this.fetch = options.fetch || (global.fetch && global.fetch.bind(global));
            this.staff = root.dataset.staff === '1';
            this.base = (root.dataset.baseUrl || '/AlgoJudge').replace(/\/$/, '');
            this.floating = root.classList.contains('aj-chatbot--floating');
            this.refs = {};
            ['panel', 'launcher', 'close', 'scroll', 'messages', 'notice', 'notice-text', 'errors', 'status', 'input', 'send', 'composer',
                'stop', 'restore', 'recovery', 'restore-confirm', 'restore-keep', 'backups', 'backup-list', 'staging', 'staging-fields', 'staging-note',
                'draft', 'draft-revision', 'draft-status', 'issues', 'draft-form', 'draft-fields', 'draft-editor', 'review-area', 'reviewed',
                'sync', 'review', 'save', 'cancel', 'cancel-confirm', 'cancel-yes', 'cancel-no', 'saved'].forEach(name => {
                this.refs[name] = root.querySelector('[data-chatbot-' + name + ']');
            });
            this.state = { draft: null, local: null, baseline: null, dirty: false, semanticDirty: false, operation: '', remoteProcessing: false,
                conflict: false, recoveryRequired: false, restoreArmed: false, cancelArmed: false, restored: false, authLost: false,
                controller: null, stopRequested: false, stopPromise: null };
            this.controls = new Map();
            this.staged = new Map();
            this.lastBackup = '';
            this.sequence = 0;
            this.ready = Promise.resolve();
            this.bind();
            this.greet();
            this.refresh();
        }

        element(tag, className = '', text) {
            const node = this.doc.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined) node.textContent = String(text);
            return node;
        }
        on(name, event, callback) { if (this.refs[name]) this.refs[name].addEventListener(event, callback); }
        bind() {
            this.on('launcher', 'click', () => this.open(this.refs.panel.hidden));
            this.on('close', 'click', () => this.open(false));
            this.on('panel', 'keydown', event => {
                if (event.key === 'Escape' && this.floating) { event.preventDefault(); this.open(false); }
            });
            this.on('composer', 'submit', event => { event.preventDefault(); this.send(); });
            this.on('input', 'keydown', event => {
                if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) { event.preventDefault(); this.send(); }
            });
            this.on('draft-form', 'submit', event => event.preventDefault());
            this.on('restore', 'click', () => this.requestRestore());
            this.on('restore-confirm', 'click', () => this.restore(true));
            this.on('restore-keep', 'click', () => {
                this.state.restoreArmed = false;
                this.refs.recovery.hidden = true;
                this.status('Đã giữ bản đang sửa. Chưa thay đổi dữ liệu máy chủ.');
                this.refresh();
            });
            this.on('stop', 'click', () => this.stopWaiting());
            this.on('sync', 'click', () => this.run('sync', () => this.syncDraft()));
            this.on('reviewed', 'change', () => this.refresh());
            this.on('review', 'click', () => this.review());
            this.on('save', 'click', () => this.save());
            this.on('cancel', 'click', () => {
                if (!this.canAct() || !this.isReview()) return;
                this.state.cancelArmed = true;
                this.refs['cancel-confirm'].hidden = false;
            });
            this.on('cancel-no', 'click', () => {
                this.state.cancelArmed = false;
                this.refs['cancel-confirm'].hidden = true;
            });
            this.on('cancel-yes', 'click', () => this.cancelDraft());
        }
        start() {
            if (!this.floating) this.ready = this.restore(false);
            return this;
        }
        open(open) {
            this.refs.panel.hidden = !open;
            this.refs.launcher.setAttribute('aria-expanded', String(open));
            if (open) {
                if (!this.state.restored && !this.state.operation) this.ready = this.restore(false);
                this.refs.input.focus();
            } else this.refs.launcher.focus();
        }
        status(message) { this.refs.status.textContent = message; }
        greet() {
            this.addMessage('assistant', this.staff
                ? 'Chào thầy/cô! Tôi hỗ trợ cách dùng AlgoJudge, tra cứu dữ liệu trong phạm vi được phép và soạn bản nháp từ đề bài. Thầy/cô có thể sửa tất cả các trường ngay tại đây; bài chỉ được tạo khi bấm Thêm bài. Tôi không thực hiện các thao tác quản trị hoặc chỉnh bài đã lưu qua chat.'
                : 'Chào bạn! Tôi hỗ trợ cách sử dụng AlgoJudge và tra cứu bài tập, bài nộp, kết quả hoặc lớp học trong phạm vi tài khoản của bạn. Tôi không cung cấp lời giải/code giải bài và không thực hiện chức năng giáo viên hoặc quản trị.');
        }
        scrollToEnd() {
            const scroll = this.refs.scroll;
            if (this.floating && scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 260) scroll.scrollTop = scroll.scrollHeight;
        }
        addMessage(role, text, links, choices) {
            const box = this.element('article', 'chatbot-message chatbot-message--' + (role === 'user' ? 'user' : 'assistant'));
            box.appendChild(this.element('span', 'chatbot-message__role', role === 'user' ? 'Bạn' : 'Trợ lý'));
            const content = this.element('div', 'chatbot-message__text', asText(text));
            box.appendChild(content);
            this.refs.messages.appendChild(box);
            const result = { box, content };
            this.messageExtras(result, links, choices);
            this.scrollToEnd();
            return result;
        }
        messageExtras(message, links, choices) {
            if (message.extras) message.extras.remove();
            const extras = this.element('div');
            if (Array.isArray(links)) {
                const list = this.element('ul', 'chatbot-message__links');
                links.forEach(link => {
                    if (!link || typeof link !== 'object') return;
                    const url = safeRelativeLink(link.url);
                    if (!url) return;
                    const item = this.element('li');
                    const anchor = this.element('a', '', asText(link.label) || 'Mở màn hình');
                    anchor.setAttribute('href', url);
                    item.appendChild(anchor);
                    list.appendChild(item);
                });
                if (list.children.length) extras.appendChild(list);
            }
            if (Array.isArray(choices)) {
                const group = this.element('div', 'chatbot-message__choices');
                choices.forEach(choice => {
                    if (!choice || typeof choice.text !== 'string' || !choice.text.trim()) return;
                    const button = this.button(asText(choice.label) || choice.text, () => this.send(choice.text));
                    button.dataset.chatbotChoice = '1';
                    group.appendChild(button);
                });
                if (group.children.length) extras.appendChild(group);
            }
            message.box.appendChild(extras);
            message.extras = extras;
            this.refresh();
        }
        button(text, action, quiet = false) {
            const button = this.element('button', 'chatbot-button' + (quiet ? ' chatbot-button--quiet' : ''), text);
            button.type = 'button';
            button.addEventListener('click', action);
            return button;
        }
        isReview() { return this.staff && this.state.draft && this.state.draft.status === 'review'; }
        canAct() { return !this.state.operation && !this.state.remoteProcessing && !this.state.conflict && !this.state.recoveryRequired && !this.state.authLost; }
        needsReview() { return !!(this.state.draft && (this.state.draft.semantic_review || this.state.semanticDirty)); }
        refresh() {
            const s = this.state;
            const busy = !!s.operation;
            const locked = busy || s.remoteProcessing || s.authLost;
            const blocked = locked || s.conflict || s.recoveryRequired;
            const active = !!this.isReview();
            this.root.setAttribute('aria-busy', String(busy));
            this.refs.send.disabled = blocked;
            this.refs.input.disabled = locked;
            this.refs.restore.disabled = busy || s.authLost;
            this.refs['restore-confirm'].disabled = busy || s.authLost;
            this.refs['restore-keep'].disabled = busy;
            this.refs.stop.hidden = !(s.operation === 'stream' || s.remoteProcessing);
            this.refs.stop.disabled = s.stopRequested || (!!s.operation && s.operation !== 'stream');
            this.root.querySelectorAll('[data-chatbot-choice]').forEach(button => { button.disabled = blocked; });
            if (!this.staff) return;
            this.refs['draft-fields'].disabled = locked || !active;
            this.refs.reviewed.disabled = blocked || !active;
            this.refs.sync.disabled = blocked || !active || !s.dirty;
            this.refs.review.disabled = blocked || !active || !this.refs.reviewed.checked;
            this.refs.save.disabled = blocked || !active || this.needsReview() || !!(s.draft && s.draft.issues.length);
            this.refs.cancel.disabled = blocked || !active;
            this.refs['cancel-yes'].disabled = blocked || !active;
            this.refs['cancel-no'].disabled = busy;
            this.refs['review-area'].hidden = !active;
            if (s.draft) {
                this.refs['draft-revision'].textContent = 'Phiên bản ' + s.draft.revision;
                this.refs['draft-status'].textContent = s.draft.status === 'saved' ? 'Đã tạo bài. Bản nháp này chỉ đọc, không thể tạo lặp.'
                    : s.draft.status === 'cancelled' ? 'Đã hủy. Bản nháp này chỉ đọc, chưa tạo bài.'
                    : s.conflict ? 'Có phiên bản mới trên máy chủ. Các ô đang sửa vẫn được giữ; hãy sao chép và khôi phục trước khi tiếp tục.'
                    : s.dirty ? 'Có chỉnh sửa chưa đồng bộ. Sẽ đồng bộ trước khi gửi yêu cầu tiếp theo; chưa tạo bài tập.'
                    : this.needsReview() ? 'Cần đối chiếu ví dụ/test và duyệt chỉnh sửa trước khi thêm bài.'
                    : s.draft.issues.length ? 'Bản nháp còn lỗi cần xử lý trước khi thêm bài.'
                    : 'Chờ thầy/cô kiểm tra. Chỉ bấm Thêm bài mới tạo bài tập thật.';
            }
        }
        clearNotice() {
            this.refs.notice.hidden = true;
            this.refs['notice-text'].textContent = '';
            this.refs.errors.replaceChildren();
            this.refs.errors.hidden = true;
            this.clearFieldErrors();
        }
        notice(message) {
            this.refs['notice-text'].textContent = message;
            this.refs.notice.hidden = false;
        }
        clearFieldErrors() {
            this.controls.forEach(({ input, error }) => { input.removeAttribute('aria-invalid'); error.textContent = ''; error.hidden = true; });
        }
        showError(error) {
            let message = error instanceof ChatbotError ? error.message : 'Không kết nối được máy chủ. Nội dung đang nhập vẫn được giữ.';
            if (error.code === 'chatbot_setup') message = 'Chatbot chưa sẵn sàng: máy chủ cần cài bản cập nhật lưu trạng thái. Vui lòng báo quản trị viên; nội dung đang nhập vẫn được giữ.';
            if (error.status === 429 || /rate.limit|quota|too_many/i.test(error.code || '')) {
                message = 'Đã chạm giới hạn yêu cầu. ' + message;
                if (/^\d+$/.test(String(error.retryAfter || ''))) message += ' Thử lại sau ' + error.retryAfter + ' giây.';
            }
            if (error.status === 409 || /revision_conflict|draft_conflict/i.test(error.code || '')) {
                this.state.conflict = true;
                this.preserveCopy();
                message += ' Không ghi đè các ô đang sửa. Bản sao được giữ trên trang; dùng Khôi phục trạng thái để nạp bản mới nhất.';
            }
            if (error.status === 401 || /auth_expired|session_expired|account_locked|unauthorized/i.test(error.code || '')) {
                this.state.authLost = true;
                this.refs.messages.replaceChildren();
                this.refs['backup-list'].replaceChildren();
                this.refs.backups.hidden = true;
                this.refs.input.value = '';
                if (this.refs.draft) this.refs.draft.hidden = true;
                if (this.refs['draft-editor']) this.refs['draft-editor'].replaceChildren();
                if (this.refs.staging) this.refs.staging.hidden = true;
                if (this.refs['staging-fields']) this.refs['staging-fields'].replaceChildren();
                this.controls.clear();
                this.staged.clear();
                this.lastBackup = '';
                this.state.draft = this.state.local = this.state.baseline = null;
                message = 'Phiên đăng nhập không còn hợp lệ. Vui lòng tải lại trang và đăng nhập để tiếp tục.';
            }
            this.notice(message);
            const errors = fieldErrors(error.fields);
            this.refs.errors.replaceChildren();
            errors.forEach(({ field, message: detail }) => {
                this.refs.errors.appendChild(this.element('li', '', (field ? (labels[field] || field) + ': ' : '') + detail));
                const path = field.replace(/\[(\d+)\]/g, '.$1');
                const control = this.controls.get(path);
                if (control) { control.input.setAttribute('aria-invalid', 'true'); control.error.textContent = detail; control.error.hidden = false; }
            });
            this.refs.errors.hidden = !errors.length;
            this.status(error.status === 429 ? 'Tạm giới hạn yêu cầu' : 'Chưa hoàn tất — dữ liệu đang nhập được giữ');
            this.refresh();
        }
        async json(endpoint, data) {
            if (!this.fetch) throw new ChatbotError('Trình duyệt chưa hỗ trợ kết nối cần thiết.', 'fetch_unavailable');
            const headers = { Accept: 'application/json' };
            const options = { method: data === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store', headers };
            if (data !== undefined) {
                headers['Content-Type'] = 'application/json';
                headers['X-CSRF-Token'] = this.doc.querySelector('meta[name="csrf-token"]')?.content || '';
                options.body = JSON.stringify(data);
            }
            const response = await this.fetch(this.base + '/backend/api/' + endpoint, options);
            let body;
            try { body = await response.json(); }
            catch (_) { throw new ChatbotError('Máy chủ trả phản hồi không phải JSON hợp lệ. Nội dung đang nhập vẫn được giữ.', 'invalid_json', response.status); }
            if (!response.ok || !body || body.ok !== true) {
                throw new ChatbotError(asText(body && body.error) || 'Yêu cầu chưa thành công.', asText(body && body.code), response.status, body && body.fields, response.headers.get('Retry-After') || '');
            }
            return body;
        }
        async run(operation, action) {
            if (!this.canAct()) return false;
            this.state.operation = operation;
            this.clearNotice();
            this.status(operation === 'save' ? 'Đang kiểm tra và lưu bài…' : operation === 'review' ? 'Đang kiểm tra bản nháp…' : operation === 'cancel' ? 'Đang hủy bản nháp…' : 'Đang đồng bộ chỉnh sửa…');
            this.refresh();
            try { await action(); return true; }
            catch (error) { this.showError(error); return false; }
            finally { this.state.operation = ''; this.refresh(); }
        }
        preserveCopy() {
            if (!this.staff || !this.state.local) return;
            const value = JSON.stringify({ draft_id: this.state.draft?.id, revision: this.state.draft?.revision,
                data: this.state.local, unsent_message: this.refs.input.value }, null, 2);
            if (value === this.lastBackup) return;
            this.lastBackup = value;
            const label = this.element('label', 'chatbot-field', 'Bản sao ' + (++this.sequence) + ' — sao chép trước khi rời trang');
            const copy = this.element('textarea');
            copy.rows = 8;
            copy.readOnly = true;
            copy.value = value;
            copy.setAttribute('aria-label', 'Bản sao chỉnh sửa ' + this.sequence);
            label.appendChild(copy);
            this.refs['backup-list'].appendChild(label);
            this.refs.backups.hidden = false;
            this.refs.backups.open = true;
        }
        requestRestore() {
            if (this.state.operation || this.state.authLost) return;
            if (this.state.dirty || this.state.conflict) {
                this.preserveCopy();
                this.state.restoreArmed = true;
                this.refs.recovery.hidden = false;
                this.status('Các ô đang sửa đã được giữ thành bản sao. Chọn nạp máy chủ hoặc giữ bản đang sửa.');
                return;
            }
            return this.restore(false);
        }
        async restore(confirmed) {
            const s = this.state;
            if (s.operation || s.authLost) return false;
            if (s.dirty || s.conflict) {
                if (!confirmed || !s.restoreArmed) { this.requestRestore(); return false; }
                this.preserveCopy();
            }
            s.operation = 'restore';
            this.status('Đang khôi phục lịch sử và bản nháp từ máy chủ…');
            this.refresh();
            try {
                const body = await this.json('ai_state.php');
                if (!Array.isArray(body.messages) || typeof body.processing !== 'boolean' || !(body.draft === null || (body.draft && typeof body.draft === 'object'))) {
                    throw new ChatbotError('Trạng thái máy chủ không đúng định dạng. Bản đang nhập vẫn được giữ.', 'invalid_state');
                }
                // Validate everything before replacing any local state.
                const draft = this.staff && body.draft ? validateEnvelope(body.draft) : null;
                this.refs.messages.replaceChildren();
                body.messages.forEach(message => {
                    if (message && ['user', 'assistant'].includes(message.role) && typeof message.text === 'string') this.addMessage(message.role, message.text, message.links);
                });
                if (!this.refs.messages.children.length) this.greet();
                s.conflict = false;
                s.recoveryRequired = false;
                s.restoreArmed = false;
                s.restored = true;
                s.remoteProcessing = body.processing;
                s.stopRequested = false;
                this.refs.recovery.hidden = true;
                this.clearNotice();
                if (draft) this.acceptDraft(draft, { force: true });
                else if (this.staff) {
                    s.draft = s.local = s.baseline = null;
                    s.dirty = s.semanticDirty = false;
                    this.refs.draft.hidden = true;
                    this.controls.clear();
                }
                if (this.staff) this.refs.staging.hidden = true;
                this.status(body.processing ? 'Yêu cầu trước vẫn đang xử lý trên máy chủ. Khôi phục lại để xem kết quả, hoặc chọn Dừng chờ.' : 'Đã khôi phục trạng thái từ máy chủ');
                return true;
            } catch (error) {
                s.recoveryRequired = true;
                this.showError(error);
                return false;
            } finally { s.operation = ''; this.refresh(); }
        }
        acceptDraft(value, options = {}) {
            if (!this.staff) return;
            const next = validateEnvelope(value);
            const s = this.state;
            if (!options.force && (s.dirty || (s.draft && s.draft.id === next.id && next.revision < s.draft.revision))) {
                throw new ChatbotError('Bản nháp trả về không khớp phiên bản đang xem.', 'revision_conflict', 409);
            }
            const acknowledged = options.keepAcknowledgement && this.refs.reviewed.checked && JSON.stringify(s.local) === JSON.stringify(next.data);
            const previous = s.draft;
            if (options.announce && previous && (previous.id !== next.id || previous.revision !== next.revision)) {
                this.addMessage('assistant', 'Bản nháp phiên bản ' + previous.revision + ' đã được thay thế. Chỉ bản nháp hiện hành bên dưới có thể được duyệt và thêm bài.');
            }
            s.draft = next;
            s.local = clone(next.data);
            s.baseline = clone(next.data);
            s.dirty = s.semanticDirty = false;
            s.cancelArmed = false;
            this.renderEditor();
            this.refs.reviewed.checked = !!acknowledged;
            this.refs['cancel-confirm'].hidden = true;
            this.refs.issues.replaceChildren();
            next.issues.forEach(issue => this.refs.issues.appendChild(this.element('li', '', issue)));
            this.refs.issues.hidden = !next.issues.length;
            this.refs.saved.replaceChildren();
            if (next.status === 'saved') {
                this.refs.saved.appendChild(this.element('p', '', next.problem_id ? 'Đã tạo bài #' + next.problem_id + '.' : 'Đã tạo bài.'));
                const url = safeRelativeLink(next.edit_url);
                if (url) {
                    const link = this.element('a', '', 'Mở bài vừa tạo');
                    link.setAttribute('href', url);
                    this.refs.saved.appendChild(link);
                }
            }
            this.refs.draft.hidden = false;
            this.refresh();
        }
        changed() {
            const s = this.state;
            s.dirty = JSON.stringify(s.local) !== JSON.stringify(s.baseline);
            s.semanticDirty = semanticFields.some(field => JSON.stringify(s.local[field]) !== JSON.stringify(s.baseline[field]));
            this.refs.reviewed.checked = false;
            this.clearFieldErrors();
            this.refresh();
        }
        field(parent, label, key, value, update, options = {}) {
            const wrap = this.element('div', 'chatbot-field' + (options.literal ? ' chatbot-field--literal' : ''));
            const id = 'aj-chatbot-field-' + (++this.sequence);
            const caption = this.element('label', '', label);
            caption.setAttribute('for', id);
            wrap.appendChild(caption);
            let input;
            if (options.choices) {
                input = this.element('select');
                const choices = options.choices.slice();
                if (!choices.some(([choice]) => choice === value)) choices.unshift([value, value || 'Chọn…']);
                choices.forEach(([choice, text]) => {
                    const option = this.element('option', '', text);
                    option.value = choice;
                    input.appendChild(option);
                });
            } else {
                input = this.element(options.rows ? 'textarea' : 'input');
                if (options.rows) input.rows = options.rows;
                else input.type = options.number ? 'number' : 'text';
                if (options.number) { input.min = '100'; input.max = '30000'; input.step = '100'; }
            }
            input.id = id;
            input.value = value;
            input.dataset.draftField = key;
            const error = this.element('span', 'chatbot-field-error');
            error.id = id + '-error';
            error.hidden = true;
            input.setAttribute('aria-describedby', error.id);
            input.addEventListener('input', () => {
                update(options.number ? (input.value === '' ? 0 : Number(input.value)) : input.value);
                this.changed();
            });
            wrap.appendChild(input);
            wrap.appendChild(error);
            parent.appendChild(wrap);
            this.controls.set(key, { input, error });
            return input;
        }
        grid(parent) { const grid = this.element('div', 'chatbot-grid'); parent.appendChild(grid); return grid; }
        check(parent, text, key, value, update) {
            const label = this.element('label', 'chatbot-check');
            const input = this.element('input');
            input.type = 'checkbox';
            input.checked = !!value;
            input.dataset.draftField = key;
            input.addEventListener('change', () => { update(input.checked); this.changed(); });
            label.appendChild(input);
            label.appendChild(this.element('span', '', text));
            parent.appendChild(label);
        }
        renderEditor() {
            const editor = this.refs['draft-editor'];
            const d = this.state.local;
            editor.replaceChildren();
            this.controls.clear();
            const add = (parent, key, options = {}) => this.field(parent, labels[key], key, d[key], value => { d[key] = value; }, options);
            add(editor, 'title');
            const meta = this.grid(editor);
            add(meta, 'slug');
            add(meta, 'topic', { choices: ['Cơ bản', 'Rẽ nhánh', 'Vòng lặp', 'Số học', 'Mảng', 'Xử lý chuỗi', 'Tìm kiếm', 'Quy hoạch động'].map(v => [v, v]) });
            add(meta, 'difficulty', { choices: [['Easy', 'Dễ'], ['Medium', 'Trung bình'], ['Hard', 'Khó']] });
            add(meta, 'suggested_lang', { choices: [['c', 'C (gcc)'], ['cpp', 'C++17'], ['java', 'Java'], ['python', 'Python 3'], ['php', 'PHP'], ['js', 'JavaScript (Node)']] });
            add(meta, 'time_limit_ms', { number: true });
            add(editor, 'description', { rows: 5 });
            const io = this.grid(editor);
            add(io, 'input_desc', { rows: 3 });
            add(io, 'output_desc', { rows: 3 });
            const sample = this.grid(editor);
            add(sample, 'sample_input', { rows: 4, literal: true });
            add(sample, 'sample_output', { rows: 4, literal: true });
            const section = this.element('section', 'chatbot-editor-section');
            section.appendChild(this.element('h4', '', 'Test case'));
            section.appendChild(this.element('p', '', 'Giữ dữ liệu literal và xuống dòng. Có thể để input rỗng nếu đề không nhận đầu vào. Kiểm tra kỹ output mong đợi của từng test.'));
            const list = this.element('div');
            section.appendChild(list);
            d.test_cases.forEach((test, index) => {
                const row = this.element('div', 'chatbot-case');
                const header = this.element('div', 'chatbot-case__header');
                header.appendChild(this.element('h5', '', 'Test #' + (index + 1)));
                header.appendChild(this.button('Xóa test ' + (index + 1), () => {
                    if (this.state.operation || !this.isReview()) return;
                    d.test_cases.splice(index, 1);
                    this.changed();
                    this.renderEditor();
                    this.refresh();
                }, true));
                row.appendChild(header);
                const cells = this.grid(row);
                this.field(cells, 'Input (stdin)', 'test_cases.' + index + '.stdin', test.stdin, value => { test.stdin = value; }, { rows: 3, literal: true });
                this.field(cells, 'Output mong đợi', 'test_cases.' + index + '.expected', test.expected, value => { test.expected = value; }, { rows: 3, literal: true });
                const checks = this.element('div', 'chatbot-checks');
                this.check(checks, 'Công khai (bỏ chọn = ẩn)', 'test_cases.' + index + '.is_public', test.is_public, value => { test.is_public = value; });
                this.check(checks, 'Ca biên (bỏ chọn = thông thường)', 'test_cases.' + index + '.is_boundary', test.is_boundary, value => { test.is_boundary = value; });
                row.appendChild(checks);
                list.appendChild(row);
            });
            section.appendChild(this.button('+ Thêm test', () => {
                if (this.state.operation || !this.isReview()) return;
                d.test_cases.push({ stdin: '', expected: '', is_public: false, is_boundary: false });
                this.changed();
                this.renderEditor();
                this.refresh();
            }));
            editor.appendChild(section);
            this.renderStringList(editor, 'assumptions', 'Các giả định AI bổ sung cần được đối chiếu với đề gốc.');
            this.renderStringList(editor, 'warnings', 'Cảnh báo không phải bằng chứng kiểm chứng thuật toán.');
        }
        renderStringList(editor, field, hint) {
            const section = this.element('section', 'chatbot-editor-section');
            section.appendChild(this.element('h4', '', labels[field]));
            section.appendChild(this.element('p', '', hint));
            const values = this.state.local[field];
            if (!values.length) section.appendChild(this.element('p', '', 'Không có.'));
            values.forEach((value, index) => {
                const row = this.element('div', 'chatbot-list-item');
                this.field(row, labels[field] + ' ' + (index + 1), field + '.' + index, value, text => { values[index] = text; }, { rows: 2 });
                row.appendChild(this.button('Xóa dòng ' + (index + 1), () => {
                    if (this.state.operation || !this.isReview()) return;
                    values.splice(index, 1);
                    this.changed(); this.renderEditor(); this.refresh();
                }, true));
                section.appendChild(row);
            });
            section.appendChild(this.button('+ Thêm ' + (field === 'assumptions' ? 'giả định' : 'cảnh báo'), () => {
                if (this.state.operation || !this.isReview()) return;
                values.push('');
                this.changed(); this.renderEditor(); this.refresh();
            }));
            editor.appendChild(section);
        }
        async syncDraft() {
            const s = this.state;
            if (!this.isReview() || !s.dirty) return;
            this.status('Đang đồng bộ chỉnh sửa trước khi tiếp tục…');
            const payload = { action: 'update', draft_id: s.draft.id, revision: s.draft.revision, data: clone(s.local) };
            const body = await this.json('ai_draft.php', payload);
            // A successful sync owns these exact inputs; failures never replace them.
            const next = validateEnvelope(body.draft);
            if (next.id !== payload.draft_id || next.revision < payload.revision) throw new ChatbotError('Kết quả đồng bộ không khớp bản nháp.', 'revision_conflict', 409);
            s.dirty = false;
            this.acceptDraft(next, { keepAcknowledgement: true });
            this.status('Đã đồng bộ chỉnh sửa. Chưa tạo bài tập.');
        }
        review() {
            if (!this.isReview() || !this.refs.reviewed.checked) return Promise.resolve(false);
            return this.run('review', async () => {
                await this.syncDraft();
                if (!this.refs.reviewed.checked) {
                    this.notice('Máy chủ đã chuẩn hóa nội dung. Hãy kiểm tra lại các ô, chọn ô đã đối chiếu rồi duyệt lại.');
                    this.status('Cần đối chiếu lại nội dung sau đồng bộ');
                    return;
                }
                const draft = this.state.draft;
                const body = await this.json('ai_draft.php', { action: 'review', draft_id: draft.id, revision: draft.revision });
                this.acceptDraft(body.draft);
                this.status(this.needsReview() ? 'Bản nháp vẫn cần duyệt' : 'Đã duyệt cấu trúc và tính nhất quán. Hãy kiểm tra trước khi thêm bài.');
            });
        }
        save() {
            if (!this.isReview()) return Promise.resolve(false);
            return this.run('save', async () => {
                await this.syncDraft();
                const draft = this.state.draft;
                if (this.needsReview() || draft.issues.length) {
                    this.notice('Bản nháp chưa thể thêm bài. Hãy sửa lỗi, đối chiếu ví dụ/test và bấm Kiểm tra và duyệt chỉnh sửa.');
                    this.status('Chờ duyệt bản nháp');
                    return;
                }
                if (!draft.save_key) throw new ChatbotError('Bản nháp thiếu khóa lưu an toàn. Hãy khôi phục trạng thái.', 'invalid_save_key');
                this.status('Đang tạo bài từ bản nháp đã duyệt…');
                const body = await this.json('ai_create_problem.php', { draft_id: draft.id, revision: draft.revision, idempotency_key: draft.save_key });
                const next = validateEnvelope(body.draft);
                if (next.status !== 'saved' || next.id !== draft.id) throw new ChatbotError('Máy chủ chưa xác nhận bài đã tạo. Hãy khôi phục trạng thái trước khi thử lại.', 'invalid_save_response');
                this.acceptDraft(next);
                this.addMessage('assistant', 'Đã tạo bài #' + (body.problem_id || next.problem_id) + ' thành công.', [{ label: 'Mở bài vừa tạo', url: body.edit_url || next.edit_url }]);
                this.status('Đã tạo bài tập');
            });
        }
        cancelDraft() {
            if (!this.isReview() || !this.state.cancelArmed) return Promise.resolve(false);
            return this.run('cancel', async () => {
                const draft = this.state.draft;
                if (this.state.dirty) this.preserveCopy();
                const body = await this.json('ai_draft.php', { action: 'cancel', draft_id: draft.id, revision: draft.revision });
                this.state.dirty = false;
                this.acceptDraft(body.draft);
                this.addMessage('assistant', 'Đã hủy bản nháp. Không có bài tập mới được tạo.');
                this.status('Đã hủy bản nháp');
            });
        }
        stageField(payload) {
            if (!this.staff || !Object.prototype.hasOwnProperty.call(labels, payload.field)) return;
            let value;
            if (typeof payload.value === 'string') value = payload.value;
            else if (typeof payload.value === 'number' || Array.isArray(payload.value)) value = JSON.stringify(payload.value, null, 2);
            else return;
            let node = this.staged.get(payload.field);
            if (!node) {
                const wrap = this.element('div', 'chatbot-staged-field');
                wrap.appendChild(this.element('h4', '', labels[payload.field]));
                node = this.element('pre');
                wrap.appendChild(node);
                this.refs['staging-fields'].appendChild(wrap);
                this.staged.set(payload.field, node);
            }
            node.textContent = value;
            this.refs.staging.hidden = false;
        }
        async send(override) {
            const s = this.state;
            if (!this.canAct()) return false;
            const originalInput = this.refs.input.value;
            const text = (typeof override === 'string' ? override : originalInput).trim();
            if (!text) { this.refs.input.focus(); return false; }
            // Sync owns the request slot first; stopping is only offered once an
            // actual streamed chat request (and its AbortController) exists.
            s.operation = 'send-sync';
            s.stopRequested = false;
            s.stopPromise = null;
            this.clearNotice();
            this.status('Đang tiếp nhận yêu cầu…');
            this.refresh();
            let message = null, streamError = null, receivedDone = false, requestStarted = false;
            try {
                await this.syncDraft();
                const payload = { text };
                if (this.isReview()) { payload.draft_id = s.draft.id; payload.revision = s.draft.revision; }
                if (this.staff) {
                    this.staged.clear();
                    this.refs['staging-fields'].replaceChildren();
                    this.refs.staging.hidden = true;
                    this.refs['staging-note'].textContent = 'Các phần nhận dần chỉ để theo dõi. Chưa thay thế bản nháp hiện hành và chưa thể lưu.';
                }
                this.addMessage('user', text);
                s.controller = new AbortController();
                s.operation = 'stream';
                this.refresh();
                requestStarted = true;
                const response = await this.fetch(this.base + '/backend/api/ai_chat.php', {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store',
                    headers: { Accept: 'text/event-stream', 'Content-Type': 'application/json', 'X-CSRF-Token': this.doc.querySelector('meta[name="csrf-token"]')?.content || '' },
                    body: JSON.stringify(payload), signal: s.controller.signal
                });
                if (!response.ok) {
                    let error = {};
                    try { error = await response.json(); } catch (_) { /* HTTP status still explains failure */ }
                    receivedDone = true; // Rejected before a stream was opened.
                    throw new ChatbotError(asText(error.error) || 'Máy chủ từ chối yêu cầu.', asText(error.code), response.status, error.fields, response.headers.get('Retry-After') || '');
                }
                if (!/\btext\/event-stream\b/i.test(response.headers.get('Content-Type') || '')) {
                    throw new ChatbotError('Máy chủ không trả luồng sự kiện như mong đợi. Hãy khôi phục trạng thái.', 'stream_unavailable');
                }
                const ensureMessage = () => { if (!message) message = this.addMessage('assistant', ''); return message; };
                const result = await consumeSSE(response, (event, data) => {
                    if (s.stopRequested || (streamError && event !== 'done')) return;
                    switch (event) {
                        case 'status': if (typeof data.message === 'string') this.status(data.message); break;
                        case 'delta':
                            if (typeof data.text !== 'string') throw new ChatbotError('Đoạn trả lời không đúng định dạng.', 'invalid_stream_event');
                            ensureMessage().content.textContent += data.text;
                            this.scrollToEnd();
                            break;
                        case 'field': this.stageField(data); break;
                        case 'draft':
                            this.acceptDraft(data.draft, { announce: true });
                            if (this.staff) this.refs.staging.hidden = true;
                            break;
                        case 'answer':
                            if (typeof data.answer !== 'string') throw new ChatbotError('Câu trả lời không đúng định dạng.', 'invalid_stream_event');
                            ensureMessage().content.textContent = data.answer;
                            this.messageExtras(message, data.links, data.choices);
                            break;
                        case 'error':
                            streamError = new ChatbotError(asText(data.error) || 'Yêu cầu chưa hoàn tất.', asText(data.code), Number.isInteger(data.status) ? data.status : 0, data.fields);
                            this.showError(streamError);
                            break;
                        case 'done': receivedDone = true; break;
                    }
                });
                if (s.stopRequested) return false;
                if (!result.ok && !streamError) throw new ChatbotError('Máy chủ kết thúc yêu cầu nhưng chưa hoàn tất. Bản nháp hiện tại vẫn được giữ.', 'stream_failed');
                if (streamError) {
                    if (message) this.markIncomplete(message);
                    return false;
                }
                if (typeof override !== 'string' && this.refs.input.value === originalInput) this.refs.input.value = '';
                this.status(this.isReview() ? 'Đã hoàn tất. Bản nháp hiện hành chờ kiểm tra.' : 'Đã trả lời');
                return true;
            } catch (error) {
                if (message) this.markIncomplete(message);
                if (!s.stopRequested) {
                    if (requestStarted && !receivedDone) {
                        s.recoveryRequired = true;
                        if (this.staff && this.staged.size) {
                            this.refs['staging-note'].textContent = 'Luồng đã gián đoạn. Đây chỉ là nội dung nhận tạm, không phải bản nháp được xác nhận và không thể lưu.';
                        }
                    }
                    this.showError(error);
                    if (s.recoveryRequired) this.notice(this.refs['notice-text'].textContent + ' Dùng Khôi phục trạng thái để kiểm tra kết quả máy chủ; hệ thống không tự ghi đè bản đang sửa.');
                }
                return false;
            } finally {
                if (s.stopPromise) await s.stopPromise;
                s.operation = '';
                s.controller = null;
                s.stopRequested = false;
                this.refresh();
            }
        }
        markIncomplete(message) {
            if (!message.incomplete) {
                message.incomplete = this.element('p', 'chatbot-message__incomplete', 'Phản hồi chưa hoàn tất. Hãy khôi phục trạng thái để kiểm tra.');
                message.box.appendChild(message.incomplete);
            }
        }
        async stopWaiting() {
            const s = this.state;
            if (s.stopRequested || (s.operation && s.operation !== 'stream') || (!s.operation && !s.remoteProcessing)) return false;
            const streaming = s.operation === 'stream';
            s.stopRequested = true;
            s.recoveryRequired = true;
            if (!streaming) s.operation = 'stop';
            if (s.controller) s.controller.abort();
            this.status('Đã dừng chờ. Đang gửi yêu cầu dừng tốt nhất có thể…');
            this.refresh();
            s.stopPromise = (async () => {
                try {
                    // Stop is available to students too; it never carries draft contents.
                    await this.json('ai_draft.php', { action: 'stop' });
                    s.remoteProcessing = false;
                    this.notice('Đã dừng chờ ở giao diện. Hủy xử lý phía nhà cung cấp chỉ là cố gắng tối đa và có thể không dừng ngay. Hãy khôi phục trạng thái trước khi tiếp tục.');
                    this.status('Đã dừng chờ — chưa xác nhận provider đã dừng xử lý');
                    return true;
                } catch (error) {
                    s.remoteProcessing = true;
                    this.showError(error);
                    this.notice('Đã dừng chờ ở giao diện nhưng chưa gửi được yêu cầu dừng máy chủ. Provider có thể vẫn xử lý. ' + this.refs['notice-text'].textContent);
                    return false;
                } finally {
                    if (!streaming) { s.stopRequested = false; s.operation = ''; this.refresh(); }
                }
            })();
            return s.stopPromise;
        }
    }

    function mount(doc) {
        const instances = [];
        if (!doc) return instances;
        doc.querySelectorAll('[data-chatbot]').forEach(root => {
            if (root.chatbot) { instances.push(root.chatbot); return; }
            root.chatbot = new Chatbot(root).start();
            instances.push(root.chatbot);
        });
        return instances;
    }
    return { createSSEParser, consumeSSE, safeRelativeLink, normalizeDraftData, validateEnvelope, ChatbotError, Chatbot, mount };
});
