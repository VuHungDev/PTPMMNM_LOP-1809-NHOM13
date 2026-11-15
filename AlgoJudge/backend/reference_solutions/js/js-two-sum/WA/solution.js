'use strict';

// Cố ý sai (WA): lưu phần tử hiện tại trước khi tra, có thể dùng chính nó hai lần (i = j).
// Phản ví dụ: stdin "3 6\n3 2 4\n"; đúng "1 2\n", mã này in "0 0\n".
const fs = require('fs');
const tokens = fs.readFileSync(0, 'utf8').trim().split(/\s+/);
const n = Number(tokens[0]);
const target = BigInt(tokens[1]);
const firstIndex = new Map();
let answer = '';

for (let j = 0; j < n; j++) {
    const value = BigInt(tokens[j + 2]);
    if (!firstIndex.has(value)) {
        firstIndex.set(value, j);
    }
    const complement = target - value;
    if (firstIndex.has(complement)) {
        answer = firstIndex.get(complement) + ' ' + j;
        break;
    }
}

process.stdout.write(answer + '\n');
