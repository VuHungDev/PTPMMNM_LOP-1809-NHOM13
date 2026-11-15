<?php
$tokens = preg_split('/\s+/', trim(stream_get_contents(STDIN)), -1, PREG_SPLIT_NO_EMPTY);
$n = (int) $tokens[0];
// Đoạn con phải không rỗng; khởi tạo từ phần tử đầu cho mảng toàn âm.
$current = (int) $tokens[1];
$best = $current;

for ($i = 2; $i <= $n; ++$i) {
    $value = (int) $tokens[$i];
    $current = max($value, $current + $value);
    $best = max($best, $current);
}
echo $best, "\n";
