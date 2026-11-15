<?php
$tokens = preg_split('/\s+/', trim(stream_get_contents(STDIN)), -1, PREG_SPLIT_NO_EMPTY);
$a = (int) $tokens[0];
$b = (int) $tokens[1];
$count = $b - $a + 1;
$endpoints = $a + $b;

// Chia thừa số chẵn trước khi nhân, giữ toàn bộ phép tính ở kiểu int64.
if ($count % 2 === 0) {
    $sum = intdiv($count, 2) * $endpoints;
} else {
    $sum = $count * intdiv($endpoints, 2);
}
echo $sum, "\n";
