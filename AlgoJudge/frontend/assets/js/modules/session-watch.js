// frontend/assets/js/modules/session-watch.js — ES module: polling phiên, tự đăng xuất khi bị khóa/xóa.
import { API_BASE, kickToLogin } from './http.js';

let started = false;

export function startSessionWatch({ intervalMs = 20000 } = {}) {
    if (started) return;
    started = true;

    async function check() {
        try {
            const res = await fetch(API_BASE + '/session_status.php', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) {
                kickToLogin('locked');
                return;
            }
            const data = await res.json();
            if (data && data.valid === false) kickToLogin('locked');
        } catch (e) { /* mạng lỗi: lần sau thử lại */ }
    }

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) check();
    });
    window.addEventListener('focus', check);
    setInterval(check, intervalMs);
}
