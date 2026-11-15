<?php
// WA cố ý: đảo chiều comparator, thu được thứ tự tăng dần.
// Phản ví dụ: "3\n3 1 2\n" in "1 2 3" thay vì "3 2 1".
$tokens = preg_split('/\s+/', trim(stream_get_contents(STDIN)), -1, PREG_SPLIT_NO_EMPTY);
$n = (int) $tokens[0];
$numbers = [];
for ($i = 1; $i <= $n; ++$i) {
    $numbers[] = (int) $tokens[$i];
}

usort($numbers, static function (int $left, int $right): int {
    return $left <=> $right;
});
echo implode(' ', $numbers), "\n";
