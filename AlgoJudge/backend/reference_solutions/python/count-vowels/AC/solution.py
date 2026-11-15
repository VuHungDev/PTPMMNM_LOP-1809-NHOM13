"""Lời giải đúng: đếm a, e, i, o, u cả hoa và thường trên toàn dòng."""

import sys


def main():
    text = sys.stdin.buffer.readline().decode("utf-8")
    if text.endswith("\r\n"):
        text = text[:-2]
    elif text.endswith("\n"):
        text = text[:-1]
    vowels = "aeiouAEIOU"
    print(sum(1 for character in text if character in vowels))


if __name__ == "__main__":
    main()
