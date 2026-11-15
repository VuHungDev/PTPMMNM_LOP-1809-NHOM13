"""Lời giải đúng: xét cả quy tắc năm tròn thế kỷ."""

import sys


def main():
    year = int(sys.stdin.buffer.read())
    is_leap = year % 400 == 0 or (year % 4 == 0 and year % 100 != 0)
    print("YES" if is_leap else "NO")


if __name__ == "__main__":
    main()
