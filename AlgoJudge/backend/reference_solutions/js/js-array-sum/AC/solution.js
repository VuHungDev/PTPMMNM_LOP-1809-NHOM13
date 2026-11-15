'use strict';

const fs = require('fs');
const tokens = fs.readFileSync(0, 'utf8').trim().split(/\s+/);
const n = Number(tokens[0]);
let sum = 0n;

// Đọc trực tiếp từng số nguyên bằng BigInt để không mất độ chính xác.
for (let i = 0; i < n; i++) {
    sum += BigInt(tokens[i + 1]);
}

process.stdout.write(sum.toString() + '\n');
