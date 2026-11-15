"""Lời giải đúng: loại số nhỏ hơn 2, thử ước đến căn bậc hai."""

import sys


def is_prime(n):
    if n < 2:
        return False
    if n % 2 == 0:
        return n == 2
    divisor = 3
    while divisor * divisor <= n:
        if n % divisor == 0:
            return False
        divisor += 2
    return True


def main():
    n = int(sys.stdin.buffer.read())
    print("TRUE" if is_prime(n) else "FALSE")


if __name__ == "__main__":
    main()
