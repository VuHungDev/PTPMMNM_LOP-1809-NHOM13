'use strict';

// Cố ý sai (WA): đặt ways(0) = 0, làm lệch dãy số cách thành Fibonacci(N).
// Phản ví dụ: stdin "2\n"; đúng "2\n" (1+1 và 2), mã này in "1\n".
const fs = require('fs');
const n = Number(fs.readFileSync(0, 'utf8').trim());
let previous = 0;
let current = 1;

for (let step = 2; step <= n; step++) {
    const next = previous + current;
    previous = current;
    current = next;
}

process.stdout.write(String(current) + '\n');
