<?php
// The floating launcher and the dedicated page use the same chatbot component.
if (empty($user) || !empty($hideAIWidget)) {
    return;
}
$chatbotVariant = 'floating';
require __DIR__ . '/chatbot_panel.php';
