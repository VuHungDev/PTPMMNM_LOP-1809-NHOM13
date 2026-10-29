// frontend/assets/js/modules/http.js — ES module: fetch JSON + tự đá về login khi 401.
export const API_BASE = '/AlgoJudge/backend/api';
export const LOGIN_URL = '/AlgoJudge/frontend/auth/login.php';

export async function apiFetch(path, { method = 'GET', body = null, onAuthExpired = null } = {}) {
    const opts = { method, credentials: 'same-origin', headers: {} };
    if (body !== null) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = typeof body === 'string' ? body : JSON.stringify(body);
    }
    const res = await fetch(API_BASE + path, opts);
    let data = null;
    try {
        data = await res.json();
    } catch (e) {
        data = { ok: false, error: 'Máy chủ trả về dữ liệu không hợp lệ.' };
    }
    if (res.status === 401) {
        if (typeof onAuthExpired === 'function') {
            onAuthExpired(data);
        } else {
            setTimeout(() => window.location.replace(LOGIN_URL + '?reason=expired'), 1200);
        }
    }
    return { res, data };
}

export function kickToLogin(reason = 'expired') {
    window.location.replace(`${LOGIN_URL}?reason=${encodeURIComponent(reason)}`);
}
