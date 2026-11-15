<?php
// WA cố ý: điều kiện i > 0 bỏ quên ký tự ở chỉ số 0.
// Phản ví dụ: "hello\n" in "olle" thay vì "olleh".
$text = rtrim((string) fgets(STDIN), "\r\n");
for ($i = strlen($text) - 1; $i > 0; --$i) {
    echo $text[$i];
}
echo "\n";
