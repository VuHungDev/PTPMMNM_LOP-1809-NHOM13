'use strict';

const fs = require('fs');
const tokens = fs.readFileSync(0, 'utf8').trim().split(/\s+/);
const n = Number(tokens[0]);
// N <= 100000: mọi tổng trung gian đều nhỏ hơn Number.MAX_SAFE_INTEGER.
let missing = n * (n + 1) / 2;

for (let i = 1; i < n; i++) {
    missing -= Number(tokens[i]);
}

process.stdout.write(String(missing) + '\n');
