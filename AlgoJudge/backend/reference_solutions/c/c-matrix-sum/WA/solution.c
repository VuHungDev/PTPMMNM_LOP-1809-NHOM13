#include <stdio.h>

/*
 * WA có chủ đích: đặt tổng về 0 ở đầu mỗi hàng, chỉ giữ tổng hàng cuối.
 * Phản ví dụ: stdin "2 3\n1 2 3\n4 5 6\n" cần "21\n",
 * nhưng mã này in "15\n".
 */
int main(void) {
    int n, m;
    if (scanf("%d %d", &n, &m) != 2) {
        return 0;
    }

    long long sum = 0;
    for (int row = 0; row < n; ++row) {
        sum = 0;
        for (int column = 0; column < m; ++column) {
            long long value;
            if (scanf("%lld", &value) != 1) {
                return 0;
            }
            sum += value;
        }
    }
    printf("%lld\n", sum);
    return 0;
}
