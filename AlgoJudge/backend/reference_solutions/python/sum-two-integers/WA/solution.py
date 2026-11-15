"""Ví dụ WA có chủ đích: cộng trị tuyệt đối thay vì cộng hai số có dấu.

Phản ví dụ: stdin '-10 25' phải in 15, nhưng chương trình in 35.
"""

import sys


def main():
    a, b = map(int, sys.stdin.buffer.read().split())
    print(abs(a) + abs(b))


if __name__ == "__main__":
    main()
