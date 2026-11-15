<?php
// WA cố ý: chỉ nhận phần dư 1, bỏ sót số lẻ âm có phần dư -1.
// Phản ví dụ: "1\n-7\n" in 0, trong khi đáp án đúng là 1.
$tokens = preg_split('/\s+/', trim(stream_get_contents(STDIN)), -1, PREG_SPLIT_NO_EMPTY);
$n = (int) $tokens[0];
$count = 0;

for ($i = 1; $i <= $n; ++$i) {
    if ((int) $tokens[$i] % 2 === 1) {
        ++$count;
    }
}
echo $count, "\n";
