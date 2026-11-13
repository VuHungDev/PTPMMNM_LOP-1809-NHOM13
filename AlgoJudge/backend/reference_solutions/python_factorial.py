def giai_thua(n):
    if n == 0:
        return 1
    kq = 1
    for i in range(1, n + 1):
        kq *= i
    return kq


def main():
    n = int(input())
    print(giai_thua(n))


if __name__ == "__main__":
    main()
