'use strict';

// Cố ý sai (WA): đổi toàn bộ sang chữ thường, vi phạm so sánh phân biệt hoa thường.
// Phản ví dụ: stdin "Aa\n"; đúng "NO\n", mã này in "YES\n".
const fs = require('fs');
const text = fs.readFileSync(0, 'utf8').replace(/\r?\n$/, '').toLowerCase();
const characters = Array.from(text);
let isPalindrome = true;

for (let left = 0, right = characters.length - 1; left < right; left++, right--) {
    if (characters[left] !== characters[right]) {
        isPalindrome = false;
        break;
    }
}

process.stdout.write(isPalindrome ? 'YES\n' : 'NO\n');
