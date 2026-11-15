#include <stdio.h>

/*
 * WA có chủ đích: tính hiệu có dấu, quên lấy trị tuyệt đối khi A < B.
 * Phản ví dụ: stdin "3 10\n" cần "7\n", nhưng mã này in "-7\n".
 */
int main(void) {
    long long a, b;
    if (scanf("%lld %lld", &a, &b) != 2) {
        return 0;
    }

    printf("%lld\n", a - b);
    return 0;
}
