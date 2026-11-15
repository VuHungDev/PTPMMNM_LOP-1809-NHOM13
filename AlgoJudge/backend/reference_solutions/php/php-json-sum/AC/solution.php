<?php
// Dữ liệu là các số phân cách bằng dấu phẩy hoặc khoảng trắng, không phải JSON.
$numbers = preg_split('/[,\s]+/', trim(stream_get_contents(STDIN)), -1, PREG_SPLIT_NO_EMPTY);
$sum = 0;
foreach ($numbers as $number) {
    $sum += (int) $number;
}
echo $sum, "\n";
