<?php
$sid = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['sid'] ?? '');
$to  = $_GET['to'] ?? '/AlgoJudge/frontend/index.php';
if ($sid !== '' && strpos($to, '/AlgoJudge/') === 0) {
    header('Set-Cookie: PHPSESSID=' . $sid . '; path=/');
    header('Location: ' . $to);
    exit;
}
echo 'invalid';
