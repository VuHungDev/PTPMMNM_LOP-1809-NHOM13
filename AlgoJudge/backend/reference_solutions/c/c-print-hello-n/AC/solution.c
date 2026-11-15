#include <stdio.h>

int main(void) {
    int n;
    if (scanf("%d", &n) != 1) {
        return 0;
    }

    for (int i = 0; i < n; ++i) {
        puts("Hello");
    }
    return 0;
}
