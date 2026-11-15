#include <stdio.h>

int main(void) {
    int a, b;
    if (scanf("%d %d", &a, &b) != 2) {
        return 0;
    }

    static unsigned char composite[100001];
    composite[0] = composite[1] = 1;
    for (int p = 2; p * p <= b; ++p) {
        if (!composite[p]) {
            for (int multiple = p * p; multiple <= b; multiple += p) {
                composite[multiple] = 1;
            }
        }
    }

    int found = 0;
    for (int value = a; value <= b; ++value) {
        if (!composite[value]) {
            printf("%d\n", value);
            found = 1;
        }
    }
    if (!found) {
        puts("0");
    }
    return 0;
}
