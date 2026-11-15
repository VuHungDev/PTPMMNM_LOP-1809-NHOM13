<?php
$text = rtrim((string) fgets(STDIN), "\r\n");
$frequency = array_fill(0, 26, 0);
$length = strlen($text);
for ($i = 0; $i < $length; ++$i) {
    ++$frequency[ord($text[$i]) - ord('a')];
}

// Duyệt a..z, chỉ thay khi lớn hơn để giữ ký tự nhỏ nhất khi hòa.
$best = 0;
for ($i = 1; $i < 26; ++$i) {
    if ($frequency[$i] > $frequency[$best]) {
        $best = $i;
    }
}
echo chr(ord('a') + $best), ' ', $frequency[$best], "\n";
