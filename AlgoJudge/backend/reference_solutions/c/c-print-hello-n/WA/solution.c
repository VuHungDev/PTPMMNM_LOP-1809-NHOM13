#include <stdio.h>

/*
 * WA có chủ đích: vòng lặp từ 0 đến N (kể cả N) in thừa một dòng.
 * Phản ví dụ: stdin "2\n" cần 2 dòng Hello, nhưng mã này in 3 dòng.
 */
int main(void) {
    int n;
    if (scanf("%d", &n) != 1) {
        return 0;
    }

    for (int i = 0; i <= n; ++i) {
        puts("Hello");
    }
    return 0;
}
