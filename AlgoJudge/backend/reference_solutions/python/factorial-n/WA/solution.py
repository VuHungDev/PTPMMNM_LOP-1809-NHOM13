"""Ví dụ WA có chủ đích: cận cuối range loại N, bỏ mất thừa số N.

Phản ví dụ: stdin '5' phải in 120, nhưng chương trình in 24.
"""

import sys


def main():
    n = int(sys.stdin.buffer.read())
    result = 1
    for factor in range(2, n):
        result *= factor
    print(result)


if __name__ == "__main__":
    main()
