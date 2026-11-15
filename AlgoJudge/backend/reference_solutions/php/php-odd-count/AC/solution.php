<?php
$tokens = preg_split('/\s+/', trim(stream_get_contents(STDIN)), -1, PREG_SPLIT_NO_EMPTY);
$n = (int) $tokens[0];
$count = 0;

for ($i = 1; $i <= $n; ++$i) {
    // Trong PHP, số lẻ âm có phần dư -1, không phải 1.
    if ((int) $tokens[$i] % 2 !== 0) {
        ++$count;
    }
}
echo $count, "\n";
