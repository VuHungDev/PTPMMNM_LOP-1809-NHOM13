<?php
// Đọc dạng chuỗi để giữ chính xác mọi chữ số tới 10^18.
$number = trim(stream_get_contents(STDIN));
$largest = 0;
$length = strlen($number);

for ($i = 0; $i < $length; ++$i) {
    $digit = ord($number[$i]) - ord('0');
    if ($digit > $largest) {
        $largest = $digit;
    }
}
echo $largest, "\n";
