'use strict';

const fs = require('fs');
const tokens = fs.readFileSync(0, 'utf8').trim().split(/\s+/);
const n = Number(tokens[0]);
const distinct = new Set();

for (let i = 1; i <= n; i++) {
    distinct.add(BigInt(tokens[i]));
}

const sorted = Array.from(distinct);
// Comparator phải trả Number, không trừ trực tiếp hai BigInt.
sorted.sort((a, b) => a < b ? -1 : a > b ? 1 : 0);
process.stdout.write(sorted.map(value => value.toString()).join(' ') + '\n');
