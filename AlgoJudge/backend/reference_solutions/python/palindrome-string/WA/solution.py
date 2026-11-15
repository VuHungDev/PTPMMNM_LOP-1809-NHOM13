"""Ví dụ WA có chủ đích: tự chuyển thành chữ thường trước khi so sánh.

Phản ví dụ: stdin 'Aa' phải in NO (phân biệt hoa thường),
nhưng chương trình biến thành 'aa' và in YES.
"""

import sys


def main():
    s = sys.stdin.buffer.readline().decode("utf-8")
    if s.endswith("\r\n"):
        s = s[:-2]
    elif s.endswith("\n"):
        s = s[:-1]
    s = s.lower()
    print("YES" if s == s[::-1] else "NO")


if __name__ == "__main__":
    main()
