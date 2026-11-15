'use strict';

// Cố ý sai (WA): khởi tạo min và max bằng 0 dù 0 không nhất thiết thuộc mảng.
// Phản ví dụ: stdin "2\n5 9\n"; đúng "5 9\n", mã này in "0 9\n".
const fs = require('fs');
const tokens = fs.readFileSync(0, 'utf8').trim().split(/\s+/);
const n = Number(tokens[0]);
let minimum = 0n;
let maximum = 0n;

for (let i = 1; i <= n; i++) {
    const value = BigInt(tokens[i]);
    if (value < minimum) minimum = value;
    if (value > maximum) maximum = value;
}

process.stdout.write(minimum.toString() + ' ' + maximum.toString() + '\n');
