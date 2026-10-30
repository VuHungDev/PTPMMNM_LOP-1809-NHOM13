<?php
// backend/config/ai.php — Cấu hình AI dùng cho chatbot.
// Key lấy từ biến môi trường GEMINI_API_KEY (file .env ở gốc project, KHÔNG commit).
// Chưa có key vẫn chạy được nhờ chế độ bóc tách tự động cục bộ.
$__aiKey = $_ENV['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY');
if (!is_string($__aiKey)) $__aiKey = '';
return [
    'api_key' => trim($__aiKey),
    'provider' => 'gemini', // gemini | openai
    'model' => 'gemini-2.0-flash',
    'timeout' => 60,
];
