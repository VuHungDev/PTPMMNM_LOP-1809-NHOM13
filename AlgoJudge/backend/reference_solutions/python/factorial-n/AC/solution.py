"""Lời giải đúng: nhân đủ 2 đến N; 0! và 1! đều bằng 1."""

import sys


def main():
    n = int(sys.stdin.buffer.read())
    result = 1
    for factor in range(2, n + 1):
        result *= factor
    print(result)


if __name__ == "__main__":
    main()
