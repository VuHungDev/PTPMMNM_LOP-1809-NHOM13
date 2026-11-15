#include <stdio.h>

/*
 * WA có chủ đích: dùng công thức tổng 1..N-1 nên bỏ mất số hạng N.
 * Phản ví dụ: stdin "5\n" cần "15\n", nhưng mã này in "10\n".
 */
int main(void) {
    long long n;
    if (scanf("%lld", &n) != 1) {
        return 0;
    }

    long long sum = n * (n - 1) / 2;
    printf("%lld\n", sum);
    return 0;
}
