'use strict';

const fs = require('fs');
const text = fs.readFileSync(0, 'utf8').replace(/\r?\n$/, '');
const vowels = new Set('aeiou');
let count = 0;

for (const character of text.toLowerCase()) {
    if (vowels.has(character)) {
        count++;
    }
}

process.stdout.write(String(count) + '\n');
