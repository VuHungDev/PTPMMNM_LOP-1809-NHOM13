<?php
// frontend/partials/logo.php — Logo AlgoJudge dùng chung cho mọi trang (sidebar, login, register).
// Biến đầu vào: $logoSize (int, chiều cao px của biểu tượng, mặc định 34).
// Màu chữ kế thừa từ phần tử cha (trắng trên nền tối); chữ "Judge" luôn xanh cyan.
$logoSize = isset($logoSize) ? (int)$logoSize : 34;
$logoSize = max(20, min(96, $logoSize));
if (!isset($GLOBALS['__aj_logo_count'])) $GLOBALS['__aj_logo_count'] = 0;
$__logoUid = 'ajlg' . (++$GLOBALS['__aj_logo_count']);
?>
<span class="aj-logo" style="--aj-logo-size: <?= $logoSize ?>px;">
    <svg class="aj-logo-mark" width="<?= $logoSize ?>" height="<?= $logoSize ?>" viewBox="0 0 48 48" role="img" aria-label="Logo AlgoJudge">
        <defs>
            <linearGradient id="<?= $__logoUid ?>" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="#171c61"/>
                <stop offset="0.55" stop-color="#2c31cf"/>
                <stop offset="1" stop-color="#0ea5e9"/>
            </linearGradient>
        </defs>
        <rect x="2" y="2" width="44" height="44" rx="11" fill="url(#<?= $__logoUid ?>)"/>
        <path d="M17.5 15.5 L10 24 L17.5 32.5" fill="none" stroke="#ffffff" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M30.5 15.5 L38 24 L30.5 32.5" fill="none" stroke="#ffffff" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M27.5 12.5 L20.5 35.5" fill="none" stroke="#38bdf8" stroke-width="3.4" stroke-linecap="round"/>
    </svg>
    <span class="aj-logo-text">Algo<span>Judge</span></span>
</span>
