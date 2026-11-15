#include <iostream>

// Intentional WA: zero is not a valid initial maximum for negative arrays.
// Counterexample: "3\n-9 -3 -5\n"; expected "-5\n", prints "0\n".
int main() {
    std::ios::sync_with_stdio(false);
    std::cin.tie(nullptr);

    int n;
    if (!(std::cin >> n)) {
        return 0;
    }
    long long largest = 0;
    long long second = 0;
    for (int i = 0; i < n; ++i) {
        long long value;
        std::cin >> value;
        if (value > largest) {
            second = largest;
            largest = value;
        } else if (value > second) {
            second = value;
        }
    }
    std::cout << second << '\n';
    return 0;
}
