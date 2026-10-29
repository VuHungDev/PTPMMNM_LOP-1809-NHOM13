<?php
// backend/api/session_status.php — Cho trình duyệt hỏi định kỳ: phiên hiện tại còn hiệu lực không?
// Auth::user() đã đối chiếu với DB nên nếu tài khoản bị khóa/xóa, endpoint này trả
// valid=false và JS sẽ chuyển về trang đăng nhập ngay, kể cả tab đang mở dở.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/Auth.php';

$u = Auth::user();
if (!$u) {
    echo json_encode(['ok' => true, 'valid' => false]);
    exit;
}
echo json_encode([
    'ok' => true,
    'valid' => true,
    'user' => [
        'id' => $u['id'],
        'username' => $u['username'],
        'role' => $u['role'],
        'class_code' => $u['class_code'],
        'xp_points' => $u['xp_points'],
    ],
]);
