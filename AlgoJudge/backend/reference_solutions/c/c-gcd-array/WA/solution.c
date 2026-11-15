#include <stdio.h>

/*
 * WA có chủ đích: khởi tạo GCD bằng 1 thay vì 0, nên kết quả luôn là 1.
 * Phản ví dụ: stdin "3\n12 18 24\n" cần "6\n",
 * nhưng mã này in "1\n".
 */
static long long gcd(long long a, long long b) {
    while (b != 0) {
        long long remainder = a % b;
        a = b;
        b = remainder;
    }
    return a;
}

int main(void) {
    int n;
    if (scanf("%d", &n) != 1) {
        return 0;
    }

    long long answer = 1;
    for (int i = 0; i < n; ++i) {
        long long value;
        if (scanf("%lld", &value) != 1) {
            return 0;
        }
        answer = gcd(answer, value);
    }
    printf("%lld\n", answer);
    return 0;
}
