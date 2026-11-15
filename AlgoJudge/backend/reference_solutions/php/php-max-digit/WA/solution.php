<?php
// WA cố ý: duyệt từ chỉ số 1 nên bỏ qua chữ số đầu tiên.
// Phản ví dụ: "93842\n" in 8, trong khi chữ số lớn nhất là 9.
$number = trim(stream_get_contents(STDIN));
$largest = 0;
$length = strlen($number);

for ($i = 1; $i < $length; ++$i) {
    $digit = ord($number[$i]) - ord('0');
    if ($digit > $largest) {
        $largest = $digit;
    }
}
echo $largest, "\n";
