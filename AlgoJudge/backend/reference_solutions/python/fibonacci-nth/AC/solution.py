"""Lời giải đúng: cập nhật F_i, F_(i+1) bằng số nguyên Python."""

import sys


def main():
    n = int(sys.stdin.buffer.read())
    current, following = 0, 1
    for _ in range(n):
        current, following = following, current + following
    print(current)


if __name__ == "__main__":
    main()
