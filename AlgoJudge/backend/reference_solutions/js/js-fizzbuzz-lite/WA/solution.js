'use strict';

// Cố ý sai (WA): bỏ trường hợp chia hết đồng thời cho 3 và 5.
// Phản ví dụ: stdin "15\n"; dòng 15 phải là "FizzBuzz", mã này in "Fizz".
const fs = require('fs');
const n = Number(fs.readFileSync(0, 'utf8').trim());
const lines = [];

for (let i = 1; i <= n; i++) {
    if (i % 3 === 0) {
        lines.push('Fizz');
    } else if (i % 5 === 0) {
        lines.push('Buzz');
    } else {
        lines.push(String(i));
    }
}

process.stdout.write(lines.join('\n') + '\n');
