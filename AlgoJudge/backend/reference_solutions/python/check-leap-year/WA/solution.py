"""Ví dụ WA có chủ đích: chỉ xét chia hết cho 4, quên ngoại lệ thế kỷ.

Phản ví dụ: stdin '1900' phải in NO, nhưng chương trình in YES.
"""

import sys


def main():
    year = int(sys.stdin.buffer.read())
    print("YES" if year % 4 == 0 else "NO")


if __name__ == "__main__":
    main()
