"""Ví dụ WA có chủ đích: khởi tạo max bằng 0 dù mảng có thể toàn âm.

Phản ví dụ: stdin '4\n-5 -2 -10 -1' phải in -1, nhưng chương trình in 0.
"""

import sys


def main():
    tokens = iter(map(int, sys.stdin.buffer.read().split()))
    n = next(tokens)
    largest = 0
    for _ in range(n):
        value = next(tokens)
        if value > largest:
            largest = value
    print(largest)


if __name__ == "__main__":
    main()
