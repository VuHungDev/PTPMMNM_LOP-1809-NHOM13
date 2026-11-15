'use strict';

const fs = require('fs');
const tokens = fs.readFileSync(0, 'utf8').trim().split(/\s+/);
const n = Number(tokens[0]);
const target = BigInt(tokens[1]);
const firstIndex = new Map();
let answer = '';

// Tìm chỉ số j nhỏ nhất; tra trước khi lưu để luôn có i < j.
for (let j = 0; j < n; j++) {
    const value = BigInt(tokens[j + 2]);
    const complement = target - value;
    if (firstIndex.has(complement)) {
        answer = firstIndex.get(complement) + ' ' + j;
        break;
    }
    if (!firstIndex.has(value)) {
        firstIndex.set(value, j);
    }
}

process.stdout.write(answer + '\n');
