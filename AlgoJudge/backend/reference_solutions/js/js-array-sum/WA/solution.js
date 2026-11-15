'use strict';

// Cố ý sai (WA): vòng lặp chỉ cộng N - 1 phần tử, bỏ số cuối.
// Phản ví dụ: stdin "3\n1 2 3\n"; đúng "6\n", mã này in "3\n".
const fs = require('fs');
const tokens = fs.readFileSync(0, 'utf8').trim().split(/\s+/);
const n = Number(tokens[0]);
let sum = 0n;

for (let i = 0; i < n - 1; i++) {
    sum += BigInt(tokens[i + 1]);
}

process.stdout.write(sum.toString() + '\n');
