<?php
// WA cố ý: cho phép đoạn rỗng có tổng 0, sai khi mọi phần tử đều âm.
// Phản ví dụ: "3\n-5 -1 -8\n" in 0 thay vì -1.
$tokens = preg_split('/\s+/', trim(stream_get_contents(STDIN)), -1, PREG_SPLIT_NO_EMPTY);
$n = (int) $tokens[0];
$current = 0;
$best = 0;

for ($i = 1; $i <= $n; ++$i) {
    $value = (int) $tokens[$i];
    $current = max(0, $current + $value);
    $best = max($best, $current);
}
echo $best, "\n";
