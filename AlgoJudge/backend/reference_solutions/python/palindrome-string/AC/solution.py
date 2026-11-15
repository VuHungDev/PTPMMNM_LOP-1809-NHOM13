"""Lời giải đúng: so sánh chính xác chuỗi với chuỗi đảo, không đổi hoa thường."""

import sys


def main():
    s = sys.stdin.buffer.readline().decode("utf-8")
    if s.endswith("\r\n"):
        s = s[:-2]
    elif s.endswith("\n"):
        s = s[:-1]
    print("YES" if s == s[::-1] else "NO")


if __name__ == "__main__":
    main()
