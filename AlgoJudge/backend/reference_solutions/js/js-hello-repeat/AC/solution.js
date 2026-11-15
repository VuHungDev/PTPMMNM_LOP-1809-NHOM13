'use strict';

const fs = require('fs');
const n = Number(fs.readFileSync(0, 'utf8').trim());
const lines = [];

for (let i = 0; i < n; i++) {
    lines.push('Hello, JS!');
}

process.stdout.write(lines.join('\n') + '\n');
