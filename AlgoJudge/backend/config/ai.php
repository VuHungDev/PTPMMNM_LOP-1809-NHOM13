<?php
// Bí mật chỉ lấy từ môi trường/.env; không ghi key hoặc nội dung đề vào log.
$env = static function (string $name, string $default = ''): string {
    $value = $_ENV[$name] ?? getenv($name);
    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
};
$provider = strtolower($env('AI_PROVIDER', 'gemini'));
$timeout = max(0, (int)$env('AI_TIMEOUT', '0'));
return [
    'provider' => $provider,
    'api_key' => $env($provider === 'gemini' ? 'GEMINI_API_KEY' : 'OPENAI_API_KEY'),
    'model' => $env('AI_MODEL', 'gemini-3.5-flash'),
    'base_url' => $env('AI_BASE_URL', 'https://api.openai.com/v1'),
    // 0 waits for the provider response without a time limit; a positive value opts
    // back into a bounded timeout (seconds) for each call, including completion.
    'timeout' => $timeout === 0 ? 0 : max(5, min(180, $timeout)),
];
