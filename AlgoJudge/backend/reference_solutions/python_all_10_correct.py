# Lời giải chuẩn (AC) cho cả 10 bài tập bằng Python 3

# Bài 1: Tính tổng hai số nguyên A và B
def prob1_sum(stdin_str):
    a, b = map(int, stdin_str.split())
    return str(a + b)

# Bài 2: Kiểm tra năm nhuận
def prob2_leap_year(stdin_str):
    n = int(stdin_str.strip())
    return "YES" if (n % 400 == 0 or (n % 4 == 0 and n % 100 != 0)) else "NO"

# Bài 3: Tính giai thừa N!
def prob3_factorial(stdin_str):
    n = int(stdin_str.strip())
    res = 1
    for i in range(2, n + 1):
        res *= i
    return str(res)

# Bài 4: Ước chung lớn nhất (GCD)
def prob4_gcd(stdin_str):
    import math
    a, b = map(int, stdin_str.split())
    return str(math.gcd(a, b))

# Bài 5: Kiểm tra số nguyên tố
def prob5_prime(stdin_str):
    n = int(stdin_str.strip())
    if n < 2:
        return "FALSE"
    i = 2
    while i * i <= n:
        if n % i == 0:
            return "FALSE"
        i += 1
    return "TRUE"

# Bài 6: Số Fibonacci thứ N
def prob6_fibo(stdin_str):
    n = int(stdin_str.strip())
    if n == 0:
        return "0"
    a, b = 0, 1
    for _ in range(2, n + 1):
        a, b = b, a + b
    return str(b)

# Bài 7: Tìm phần tử lớn nhất trong mảng
def prob7_max_array(stdin_str):
    parts = list(map(int, stdin_str.split()))
    n = parts[0]
    arr = parts[1:n+1]
    return str(max(arr))

# Bài 8: Chuỗi đối xứng (Palindrome)
def prob8_palindrome(stdin_str):
    s = stdin_str.strip()
    return "YES" if s == s[::-1] else "NO"

# Bài 9: Đếm số lượng nguyên âm
def prob9_vowels(stdin_str):
    s = stdin_str.strip().lower()
    return str(sum(1 for ch in s if ch in 'aeiou'))

# Bài 10: Tìm kiếm nhị phân trên mảng tăng dần
def prob10_bsearch(stdin_str):
    parts = list(map(int, stdin_str.split()))
    n, x = parts[0], parts[1]
    arr = parts[2:2+n]
    lo, hi = 0, len(arr) - 1
    while lo <= hi:
        mid = (lo + hi) // 2
        if arr[mid] == x:
            return str(mid)
        elif arr[mid] < x:
            lo = mid + 1
        else:
            hi = mid - 1
    return "-1"
