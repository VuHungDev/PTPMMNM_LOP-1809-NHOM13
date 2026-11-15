#include <stdio.h>

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
        if (value % 2 == 0) {
            ++count;
        }
    }
    printf("%d\n", count);
    return 0;
}
