<?php
// WA cố ý: cộng số nguyên tố < N thay vì <= N, bỏ N nếu N nguyên tố.
// Phản ví dụ: "2\n" in 0, trong khi đáp án đúng là 2.
$n = (int) trim(stream_get_contents(STDIN));
if ($n < 2) {
    echo "0\n";
    exit;
}

$isPrime = array_fill(0, $n + 1, true);
$isPrime[0] = false;
$isPrime[1] = false;
for ($prime = 2; $prime * $prime <= $n; ++$prime) {
    if ($isPrime[$prime]) {
        for ($multiple = $prime * $prime; $multiple <= $n; $multiple += $prime) {
            $isPrime[$multiple] = false;
        }
    }
}

$sum = 0;
for ($value = 2; $value < $n; ++$value) {
    if ($isPrime[$value]) {
        $sum += $value;
    }
}
echo $sum, "\n";
