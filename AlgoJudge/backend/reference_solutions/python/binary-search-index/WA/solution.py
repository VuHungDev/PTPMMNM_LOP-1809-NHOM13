"""Ví dụ WA có chủ đích: dừng khi hai cận bằng nhau, bỏ phần tử cuối.

Phản ví dụ: stdin '6 6\n1 2 3 4 5 6' phải in 5,
nhưng chương trình in -1 do không kiểm tra đoạn chỉ còn chỉ số 5.
"""

import sys


def main():
    tokens = list(map(int, sys.stdin.buffer.read().split()))
    n, target = tokens[:2]
    values = tokens[2:2 + n]
    left, right = 0, n - 1
    answer = -1
    while left < right:
        middle = (left + right) // 2
        if values[middle] == target:
            answer = middle
            break
        if values[middle] < target:
            left = middle + 1
        else:
            right = middle - 1
    print(answer)


if __name__ == "__main__":
    main()
