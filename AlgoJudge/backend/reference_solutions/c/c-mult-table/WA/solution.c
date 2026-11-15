#include <stdio.h>

/*
 * WA có chủ đích: đánh số dòng 0..N-1 thay vì 1..N.
 * Phản ví dụ: stdin "2\n" cần "2 x 1 = 2\n2 x 2 = 4\n",
 * nhưng mã này in "2 x 0 = 0\n2 x 1 = 2\n".
 */
int main(void) {
    int n;
    if (scanf("%d", &n) != 1) {
        return 0;
    }

    for (int i = 0; i < n; ++i) {
        printf("%d x %d = %d\n", n, i, n * i);
    }
    return 0;
}
