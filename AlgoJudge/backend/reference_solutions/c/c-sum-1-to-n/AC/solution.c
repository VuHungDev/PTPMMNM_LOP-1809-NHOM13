#include <stdio.h>

int main(void) {
    long long n;
    if (scanf("%lld", &n) != 1) {
        return 0;
    }

    long long sum = n * (n + 1) / 2;
    printf("%lld\n", sum);
    return 0;
}
