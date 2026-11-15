<?php
// WA cố ý: bắt đầu từ 1 nhưng dùng i < N, nên chỉ in N - 1 dòng.
// Phản ví dụ: "abc\n3\n" cần 3 dòng abc, chương trình chỉ in 2 dòng.
$text = rtrim((string) fgets(STDIN), "\r\n");
$n = (int) fgets(STDIN);

for ($i = 1; $i < $n; ++$i) {
    echo $text, "\n";
}
