'use strict';

// Cố ý sai (WA): chỉ nhận nguyên âm chữ thường, quên yêu cầu không phân biệt hoa thường.
// Phản ví dụ: stdin "AEIOU\n"; đúng "5\n", mã này in "0\n".
const fs = require('fs');
const text = fs.readFileSync(0, 'utf8').replace(/\r?\n$/, '');
const vowels = new Set('aeiou');
let count = 0;

for (const character of text) {
    if (vowels.has(character)) {
        count++;
    }
}

process.stdout.write(String(count) + '\n');
