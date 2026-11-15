"""Lời giải đúng: khởi tạo giá trị lớn nhất từ phần tử đầu tiên."""

import sys


def main():
    tokens = iter(map(int, sys.stdin.buffer.read().split()))
    n = next(tokens)
    largest = next(tokens)
    for _ in range(n - 1):
        value = next(tokens)
        if value > largest:
            largest = value
    print(largest)


if __name__ == "__main__":
    main()
