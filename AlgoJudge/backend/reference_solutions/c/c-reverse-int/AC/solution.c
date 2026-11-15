#include <stdio.h>

int main(void) {
    long long n;
    if (scanf("%lld", &n) != 1) {
        return 0;
    }

    int sign = n < 0 ? -1 : 1;
    if (n < 0) {
        n = -n;
    }

    long long reversed = 0;
    while (n > 0) {
        reversed = reversed * 10 + n % 10;
        n /= 10;
    }
    printf("%lld\n", sign * reversed);
    return 0;
}
