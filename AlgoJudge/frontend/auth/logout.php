<?php
// frontend/auth/logout.php — Đăng xuất: hủy toàn bộ phiên (session + cookie).
require_once __DIR__ . '/../../backend/lib/Auth.php';
Auth::logout();
header('Location: ' . url('/frontend/auth/login.php'));
exit;
