'use strict';

const fs = require('fs');
const tokens = fs.readFileSync(0, 'utf8').trim().split(/\s+/);
const n = Number(tokens[0]);
let minimum = BigInt(tokens[1]);
let maximum = minimum;

// Duyệt tuyến tính, không truyền cả mảng lớn vào Math.min/Math.max.
for (let i = 2; i <= n; i++) {
    const value = BigInt(tokens[i]);
    if (value < minimum) minimum = value;
    if (value > maximum) maximum = value;
}

process.stdout.write(minimum.toString() + ' ' + maximum.toString() + '\n');
