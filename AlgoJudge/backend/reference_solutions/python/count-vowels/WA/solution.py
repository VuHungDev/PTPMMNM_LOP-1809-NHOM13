"""Ví dụ WA có chủ đích: chỉ nhận nguyên âm thường, quên nguyên âm hoa.

Phản ví dụ: stdin 'AEIOUaeiou' phải in 10, nhưng chương trình in 5.
"""

import sys


def main():
    text = sys.stdin.buffer.readline().decode("utf-8")
    if text.endswith("\r\n"):
        text = text[:-2]
    elif text.endswith("\n"):
        text = text[:-1]
    vowels = "aeiou"
    print(sum(1 for character in text if character in vowels))


if __name__ == "__main__":
    main()
