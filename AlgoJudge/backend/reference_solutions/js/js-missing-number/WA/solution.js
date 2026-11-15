'use strict';

// Cố ý sai (WA): dùng tổng 0..N-1 thay vì 1..N, nên kết quả bị giảm N.
// Phản ví dụ: stdin "5\n1 2 4 5\n"; đúng "3\n", mã này in "-2\n".
const fs = require('fs');
const tokens = fs.readFileSync(0, 'utf8').trim().split(/\s+/);
const n = Number(tokens[0]);
let missing = n * (n - 1) / 2;

for (let i = 1; i < n; i++) {
    missing -= Number(tokens[i]);
}

process.stdout.write(String(missing) + '\n');
