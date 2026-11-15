#include <stdio.h>

int main(void) {
    long long a, b;
    if (scanf("%lld %lld", &a, &b) != 2) {
        return 0;
    }

    long long difference = a - b;
    if (difference < 0) {
        difference = -difference;
    }
    printf("%lld\n", difference);
    return 0;
}
