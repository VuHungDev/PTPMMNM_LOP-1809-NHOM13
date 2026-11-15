#include <stdio.h>

/*
 * WA có chủ đích: đảo phần trị tuyệt đối rồi quên khôi phục dấu âm.
 * Phản ví dụ: stdin "-456\n" cần "-654\n", nhưng mã này in "654\n".
 */
int main(void) {
    long long n;
    if (scanf("%lld", &n) != 1) {
        return 0;
    }
    if (n < 0) {
        n = -n;
    }

    long long reversed = 0;
    while (n > 0) {
        reversed = reversed * 10 + n % 10;
        n /= 10;
    }
    printf("%lld\n", reversed);
    return 0;
}
