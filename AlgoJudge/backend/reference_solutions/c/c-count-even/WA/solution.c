#include <stdio.h>

/*
 * WA có chủ đích: chỉ đếm số chẵn dương, bỏ số 0 và số chẵn âm.
 * Phản ví dụ: stdin "4\n-2 -3 0 1\n" cần "2\n",
 * nhưng mã này in "0\n".
 */
int main(void) {
    int n;
    if (scanf("%d", &n) != 1) {
        return 0;
    }

    int count = 0;
    for (int i = 0; i < n; ++i) {
        long long value;
        if (scanf("%lld", &value) != 1) {
            return 0;
        }
        if (value > 0 && value % 2 == 0) {
            ++count;
        }
    }
    printf("%d\n", count);
    return 0;
}
