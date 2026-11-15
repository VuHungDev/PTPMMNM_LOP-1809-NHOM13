<?php
// WA cố ý: tính đoạn nửa mở [A, B), quên cộng số B.
// Phản ví dụ: "1 5\n" in 10 thay vì tổng đúng 15.
$tokens = preg_split('/\s+/', trim(stream_get_contents(STDIN)), -1, PREG_SPLIT_NO_EMPTY);
$a = (int) $tokens[0];
$b = (int) $tokens[1];
$count = $b - $a;
$endpoints = $a + $b - 1;

if ($count % 2 === 0) {
    $sum = intdiv($count, 2) * $endpoints;
} else {
    $sum = $count * intdiv($endpoints, 2);
}
echo $sum, "\n";
