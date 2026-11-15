"""Ví dụ WA có chủ đích: đánh số lệch một vị trí, in F_(N+1).

Phản ví dụ: stdin '6' phải in 8, nhưng chương trình in 13.
"""

import sys


def main():
    n = int(sys.stdin.buffer.read())
    current, following = 0, 1
    for _ in range(n):
        current, following = following, current + following
    print(following)


if __name__ == "__main__":
    main()
