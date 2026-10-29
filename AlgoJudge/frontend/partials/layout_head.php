<?php
// frontend/partials/layout_head.php — Phần <head> + khung layout dùng chung (sidebar dọc bên trái)
// Biến đầu vào: $pageTitle (string), $user (array|null), $activeNav (string)
$sbHasUser = !empty($user);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'AlgoJudge') ?> — AlgoJudge OJ</title>
    <link rel="icon" type="image/svg+xml" href="<?= url('/frontend/assets/img/logo.svg') ?>">
    <script>
        // Áp trạng thái thu gọn trước khi vẽ để tránh nhấp nháy layout
        (function () {
            try {
                if (localStorage.getItem('aj.sidebar') === 'collapsed') {
                    document.documentElement.classList.add('sb-collapsed');
                }
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="<?= url('/frontend/assets/css/algojudge.css') ?>?v=20261028">
    <script id="monaco-loader" src="https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.45.0/min/vs/loader.min.js"></script>
    <script id="alpine-cdn" src="<?= url('/frontend/assets/js/vendor/alpine.min.js') ?>" defer></script>
</head>
<body class="<?= $sbHasUser ? 'has-sb' : 'no-sb' ?>">
<?php if ($sbHasUser): ?>
<button type="button" class="sb-mobile-toggle" id="sbMobileToggle" aria-label="Mở menu">
    <span></span><span></span><span></span>
</button>
<?php require __DIR__ . '/sidebar.php'; ?>
<?php endif; ?>
<main class="app-main">
