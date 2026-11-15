"""Lời giải đúng: tính tổng hai số nguyên, giữ nguyên dấu."""

import sys


def main():
    a, b = map(int, sys.stdin.buffer.read().split())
    print(a + b)


if __name__ == "__main__":
    main()
