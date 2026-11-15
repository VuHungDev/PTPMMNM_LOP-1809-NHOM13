"""Lời giải đúng: thuật toán Euclid trên hai số nguyên dương."""

import sys


def main():
    a, b = map(int, sys.stdin.buffer.read().split())
    while b != 0:
        a, b = b, a % b
    print(a)


if __name__ == "__main__":
    main()
