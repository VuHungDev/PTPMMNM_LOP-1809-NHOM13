// frontend/assets/js/modules/alpine-boot.js — ES module: gắn component Alpine sau khi đăng ký.
// Vấn đề: Alpine CDN (defer) start trước ES module nên x-data="tên" lỗi "not defined"
// ngay lần đầu và bị đánh dấu bỏ qua. Giải pháp: HTML dùng data-aj-cmp="tên"
// (Alpine lần đầu không thấy nên bỏ qua sạch); module đăng ký Alpine.data xong
// mới gán x-data + initTree từng node — đảm bảo init() luôn chạy đúng 1 lần.

export function mountComponents() {
    if (!window.Alpine) return;
    document.querySelectorAll('[data-aj-cmp]').forEach((node) => {
        if (node.hasAttribute('x-data') || node.dataset.ajMounted) return;
        node.setAttribute('x-data', node.getAttribute('data-aj-cmp'));
        // Đánh dấu TRƯỚC khi initTree: init đồng bộ bên trong có thể kích hoạt
        // lại mount (qua ready dự phòng) và tạo component lần 2 (editor lặp đôi).
        node.dataset.ajMounted = '1';
        try {
            window.Alpine.initTree(node);
        } catch (e) { /* bỏ qua */ }
    });
}

let done = false;

// ready(): gọi sau khi module trang đã Alpine.data(...) xong.
export function ready() {
    if (done) return;
    done = true;
    mountComponents();
}

// Dự phòng: module trang lỗi mạng thì vẫn mount (x-data inline như { leftTab } chạy được).
setTimeout(mountComponents, 5000);
