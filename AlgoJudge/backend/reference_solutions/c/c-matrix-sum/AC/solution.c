#include <stdio.h>

int main(void) {
    int n, m;
    if (scanf("%d %d", &n, &m) != 2) {
        return 0;
    }

    long long sum = 0;
    for (int row = 0; row < n; ++row) {
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
