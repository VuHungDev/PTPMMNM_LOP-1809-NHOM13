<?php
// Giữ nguyên khoảng trắng trong S; chỉ bỏ ký tự kết thúc dòng.
$text = rtrim((string) fgets(STDIN), "\r\n");
$n = (int) fgets(STDIN);

for ($i = 0; $i < $n; ++$i) {
    echo $text, "\n";
}
