<?php
$tokens = preg_split('/\s+/', trim(stream_get_contents(STDIN)), -1, PREG_SPLIT_NO_EMPTY);
$n = (int) $tokens[0];
$numbers = [];
for ($i = 1; $i <= $n; ++$i) {
    $numbers[] = (int) $tokens[$i];
}

// So sánh trực tiếp, không trừ hai số để tránh tràn số trong comparator.
usort($numbers, static function (int $left, int $right): int {
    return $right <=> $left;
});
echo implode(' ', $numbers), "\n";
