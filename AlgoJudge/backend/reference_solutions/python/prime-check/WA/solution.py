"""Ví dụ WA có chủ đích: dùng < thay vì <= ở biên căn bậc hai.

Phản ví dụ: stdin '49' phải in FALSE, nhưng chương trình in TRUE
vì không thử ước 7 khi 7 * 7 bằng 49.
"""

import sys


def is_prime(n):
    if n < 2:
        return False
    divisor = 2
    while divisor * divisor < n:
        if n % divisor == 0:
            return False
        divisor += 1
    return True


def main():
    n = int(sys.stdin.buffer.read())
    print("TRUE" if is_prime(n) else "FALSE")


if __name__ == "__main__":
    main()
