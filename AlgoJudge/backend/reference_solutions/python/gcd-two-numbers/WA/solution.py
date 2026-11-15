"""Ví dụ WA có chủ đích: dừng Euclid sớm khi số dư còn bằng 1.

Phản ví dụ: stdin '7 13' phải in 1, nhưng chương trình in 6.
Khi b bằng 1 vẫn cần thêm bước cập nhật a trước khi trả kết quả.
"""

import sys


def main():
    a, b = map(int, sys.stdin.buffer.read().split())
    while b > 1:
        a, b = b, a % b
    print(a)


if __name__ == "__main__":
    main()
