// frontend/assets/js/modules/http.js — ES module: fetch JSON + tự đá về login khi 401.
export const API_BASE = '/AlgoJudge/backend/api';
export const LOGIN_URL = '/AlgoJudge/frontend/auth/login.php';

export async function apiFetch(path, { method = 'GET', body = null, headers = {}, onAuthExpired = null, timeoutMs = 0 } = {}) {
    const opts = { method, credentials: 'same-origin', headers: { ...headers } };
    if (!['GET', 'HEAD'].includes(method.toUpperCase())) {
        opts.headers['X-CSRF-Token'] = document.querySelector('meta[name="csrf-token"]')?.content || '';
    }
    if (body !== null) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = typeof body === 'string' ? body : JSON.stringify(body);
    }
    const bounded = Number.isFinite(timeoutMs) && timeoutMs > 0;
    const controller = bounded ? new AbortController() : null;
    if (controller) opts.signal = controller.signal;
    let timedOut = false;
    let timer;
    const timeoutError = new Error('Request timed out; the server may still be processing it.');
    timeoutError.name = 'TimeoutError';
    const request = async () => {
        const res = await fetch(API_BASE + path, opts);
        let data = null;
        try {
            data = await res.json();
        } catch (e) {
            if (timedOut) throw timeoutError;
            data = { ok: false, error: 'Máy chủ trả về dữ liệu không hợp lệ.' };
        }
        if (timedOut) throw timeoutError;
        if (res.status === 401) {
            if (typeof onAuthExpired === 'function') {
                onAuthExpired(data);
            } else {
                setTimeout(() => window.location.replace(LOGIN_URL + '?reason=expired'), 1200);
            }
        }
        return { res, data };
    };
    if (!bounded) return request();
    try {
        return await Promise.race([
            request(),
            new Promise((resolve, reject) => {
                timer = setTimeout(() => {
                    timedOut = true;
                    reject(timeoutError);
                    controller.abort(); // Stops only this browser request, not the server-side run.
                }, timeoutMs);
            }),
        ]);
    } finally {
        clearTimeout(timer);
    }
}

export function kickToLogin(reason = 'expired') {
    window.location.replace(`${LOGIN_URL}?reason=${encodeURIComponent(reason)}`);
}
