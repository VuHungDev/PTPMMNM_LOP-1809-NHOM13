'use strict';

const fs = require('fs');
const n = Number(fs.readFileSync(0, 'utf8').trim());
// ways(0) = 1 và ways(1) = 1; N <= 30 nên Number biểu diễn chính xác.
let previous = 1;
let current = 1;

for (let step = 2; step <= n; step++) {
    const next = previous + current;
    previous = current;
    current = next;
}

process.stdout.write(String(current) + '\n');
