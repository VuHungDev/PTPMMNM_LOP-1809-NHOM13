// frontend/assets/js/app.js — Thư viện JS dùng chung (nhóm tự viết).
// Gồm: helper định dạng dùng chung + điều khiển sidebar dọc (thu gọn / rê chuột bung ra / menu con xổ xuống).
window.AlgoJudge = window.AlgoJudge || {};
window.AlgoJudge.formatMs = function (ms) {
    return (ms >= 1000 ? (ms / 1000).toFixed(2) + ' s' : ms + ' ms');
};

(function () {
    'use strict';

    var STORAGE_KEY = 'aj.sidebar';
    var root = document.documentElement;
    var sidebar = document.getElementById('app-sidebar');
    if (!sidebar) return;

    var pinBtn = document.getElementById('sbPin');
    var mobileToggle = document.getElementById('sbMobileToggle');
    var collapsed = root.classList.contains('sb-collapsed');

    function persist() {
        try {
            localStorage.setItem(STORAGE_KEY, collapsed ? 'collapsed' : 'expanded');
        } catch (e) { /* chế độ riêng tư: bỏ qua */ }
    }

    function apply(logical) {
        collapsed = logical;
        root.classList.toggle('sb-collapsed', collapsed);
        if (pinBtn) {
            pinBtn.setAttribute('title', collapsed ? 'Ghim mở rộng menu' : 'Thu gọn menu');
            pinBtn.setAttribute('aria-label', collapsed ? 'Ghim mở rộng menu' : 'Thu gọn menu');
        }
        // Thu gọn thì không giữ menu con mở
        if (collapsed) sidebar.classList.remove('hovering');
    }

    // --- Rê chuột: chỉ bung tạm thời, không đổi trạng thái đã lưu ---
    sidebar.addEventListener('mouseenter', function () {
        if (collapsed) sidebar.classList.add('hovering');
    });
    sidebar.addEventListener('mouseleave', function () {
        sidebar.classList.remove('hovering');
    });
    // Khi đang rê chuột (đã bung) thì menu con xổ ra bình thường
    sidebar.addEventListener('focusin', function () {
        if (collapsed && !sidebar.classList.contains('hovering')) sidebar.classList.add('hovering');
    });

    // --- Nút ghim: đổi trạng thái cố định ---
    if (pinBtn) {
        pinBtn.addEventListener('click', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            apply(!collapsed);
            persist();
        });
    }

    // --- Menu nhóm: bấm để xổ/thu menu con ---
    sidebar.querySelectorAll('.sb-parent').forEach(function (btn) {
        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            var group = btn.closest('.sb-group');
            var willOpen = !group.classList.contains('open');

            // Accordion: mở nhóm này thì đóng các nhóm khác
            sidebar.querySelectorAll('.sb-group').forEach(function (g) {
                if (g !== group) {
                    g.classList.remove('open');
                    var b = g.querySelector('.sb-parent');
                    if (b) b.setAttribute('aria-expanded', 'false');
                }
            });

            group.classList.toggle('open', willOpen);
            btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            if (willOpen && collapsed) sidebar.classList.add('hovering');
        });
    });

    // --- Bấm vào một chức năng con: chuyển trang + tự thu gọn menu ---
    sidebar.querySelectorAll('.sb-link').forEach(function (link) {
        link.addEventListener('click', function () {
            apply(true);
            persist();
            // Không cần đóng menu con: trang sẽ được tải lại
        });
    });

    // --- Mobile: nút hamburger bật/tắt sidebar dạng overlay ---
    if (mobileToggle) {
        mobileToggle.addEventListener('click', function () {
            document.body.classList.toggle('sb-mobile-open');
        });
        document.addEventListener('click', function (ev) {
            if (!document.body.classList.contains('sb-mobile-open')) return;
            if (sidebar.contains(ev.target) || mobileToggle.contains(ev.target)) return;
            document.body.classList.remove('sb-mobile-open');
        });
    }

    // --- Phím tắt: Ctrl/Cmd + B thu gọn/mở rộng ---
    document.addEventListener('keydown', function (ev) {
        if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'b') {
            ev.preventDefault();
            apply(!collapsed);
            persist();
        }
    });

    // --- Nếu màn hình nhỏ thì luôn ở dạng thu gọn icon ---
    function syncViewport() {
        if (window.innerWidth < 1024) {
            root.classList.add('sb-compact');
        } else {
            root.classList.remove('sb-compact');
        }
    }
    syncViewport();
    window.addEventListener('resize', syncViewport);
})();

// --- Giám sát phiên đăng nhập: đã chuyển sang ES module
// frontend/assets/js/modules/session-watch.js (nạp qua layout_foot, chạy 1 lần).
// Giữ đoạn này để tương thích trang cache cũ: module mới tự chặn trùng bằng cờ
// riêng nên không poll 2 lần.
