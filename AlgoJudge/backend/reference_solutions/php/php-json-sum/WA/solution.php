<?php
// WA cố ý: chỉ tách dấu phẩy, bỏ qua khoảng trắng cũng là dấu phân cách.
// Phản ví dụ: "1 2, 3\n" ép "1 2" thành 1, in 4 thay vì 6.
$numbers = explode(',', trim(stream_get_contents(STDIN)));
$sum = 0;
foreach ($numbers as $number) {
    $sum += (int) trim($number);
}
echo $sum, "\n";
