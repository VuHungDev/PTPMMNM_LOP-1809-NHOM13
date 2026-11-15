'use strict';

// Cố ý sai (WA): dùng <= nên in N + 1 dòng thay vì N dòng.
// Phản ví dụ: stdin "1\n"; đúng một dòng "Hello, JS!", sai hai dòng.
const fs = require('fs');
const n = Number(fs.readFileSync(0, 'utf8').trim());
const lines = [];

for (let i = 0; i <= n; i++) {
    lines.push('Hello, JS!');
}

process.stdout.write(lines.join('\n') + '\n');
