<?php
// WA cố ý: dùng >= khi duyệt a..z nên chọn ký tự lớn nhất khi hòa.
// Phản ví dụ: "bbaacc\n" in "c 2", trong khi đáp án là "a 2".
$text = rtrim((string) fgets(STDIN), "\r\n");
$frequency = array_fill(0, 26, 0);
$length = strlen($text);
for ($i = 0; $i < $length; ++$i) {
    ++$frequency[ord($text[$i]) - ord('a')];
}

$best = 0;
for ($i = 1; $i < 26; ++$i) {
    if ($frequency[$i] >= $frequency[$best]) {
        $best = $i;
    }
}
echo chr(ord('a') + $best), ' ', $frequency[$best], "\n";
