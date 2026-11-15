'use strict';

const fs = require('fs');
// Chỉ bỏ dấu xuống dòng cuối; giữ nguyên khoảng trắng đầu, cuối và bên trong.
const text = fs.readFileSync(0, 'utf8').replace(/\r?\n$/, '');
const characters = Array.from(text);
let isPalindrome = true;

for (let left = 0, right = characters.length - 1; left < right; left++, right--) {
    if (characters[left] !== characters[right]) {
        isPalindrome = false;
        break;
    }
}

process.stdout.write(isPalindrome ? 'YES\n' : 'NO\n');
