'use strict';

// Cố ý sai (WA): sort() mặc định so sánh dạng chuỗi, không phải thứ tự số.
// Phản ví dụ: stdin "3\n2 10 2\n"; đúng "2 10\n", mã này in "10 2\n".
const fs = require('fs');
const tokens = fs.readFileSync(0, 'utf8').trim().split(/\s+/);
const n = Number(tokens[0]);
const distinct = new Set();

for (let i = 1; i <= n; i++) {
    distinct.add(BigInt(tokens[i]));
}

const sorted = Array.from(distinct);
sorted.sort();
process.stdout.write(sorted.map(value => value.toString()).join(' ') + '\n');
