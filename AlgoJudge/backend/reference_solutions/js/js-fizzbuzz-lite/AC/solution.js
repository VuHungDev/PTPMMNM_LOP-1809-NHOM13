'use strict';

const fs = require('fs');
const n = Number(fs.readFileSync(0, 'utf8').trim());
const lines = [];

for (let i = 1; i <= n; i++) {
    if (i % 15 === 0) {
        lines.push('FizzBuzz');
    } else if (i % 3 === 0) {
        lines.push('Fizz');
    } else if (i % 5 === 0) {
        lines.push('Buzz');
    } else {
        lines.push(String(i));
    }
}

process.stdout.write(lines.join('\n') + '\n');
