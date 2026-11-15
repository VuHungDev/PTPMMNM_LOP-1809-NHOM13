<?php
require_once __DIR__ . '/../../backend/lib/Auth.php';
$user = Auth::requireRole(['teacher', 'admin']);
$pageTitle = 'Trợ lý tạo bài tập';
$activeNav = 'prob_ai';
$hideAIWidget = true;
require FE_DIR . '/partials/layout_head.php';
?>
<div class="chatbot-page">
    <header class="chatbot-page__heading">
        <h1>Trợ lý tạo bài tập</h1>
    </header>
    <?php
    $chatbotVariant = 'page';
    require FE_DIR . '/partials/chatbot_panel.php';
    ?>
</div>
<?php require FE_DIR . '/partials/layout_foot.php'; ?>
