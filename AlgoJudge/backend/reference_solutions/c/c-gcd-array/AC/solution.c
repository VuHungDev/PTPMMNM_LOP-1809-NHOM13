#include <stdio.h>

static long long gcd(long long a, long long b) {
    while (b != 0) {
        long long remainder = a % b;
        a = b;
        b = remainder;
    }
    return a;
}

int main(void) {
    int n;
    if (scanf("%d", &n) != 1) {
        return 0;
    }

    long long answer = 0;
    for (int i = 0; i < n; ++i) {
        long long value;
        if (scanf("%lld", &value) != 1) {
            return 0;
        }
        answer = gcd(answer, value);
    }
    printf("%lld\n", answer);
    return 0;
}
