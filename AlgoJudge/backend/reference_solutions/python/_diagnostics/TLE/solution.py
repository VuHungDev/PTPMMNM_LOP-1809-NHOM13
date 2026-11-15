"""Ví dụ TLE có chủ đích cho lớp học: vòng lặp hữu hạn nhưng quá lớn.

CHỈ chạy trong Docker có giới hạn thời gian và tài nguyên; không chạy host.
Không phải lời giải bài tập. Máy chấm phải dừng khi vượt time limit.
"""

if __name__ == "__main__":
    total = 0
    for number in range(10_000_000_000):
        total += number % 2
    print(total)
