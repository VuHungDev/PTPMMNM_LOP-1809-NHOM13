#include <iostream>

// Intentional WA: counts adjacent runs without first sorting the array.
// Counterexample: "3\n1 2 1\n"; expected "2\n", prints "3\n".
int main() {
    std::ios::sync_with_stdio(false);
    std::cin.tie(nullptr);

    int n;
    if (!(std::cin >> n)) {
        return 0;
    }
    long long previous = 0;
    long long distinct = 0;
    for (int i = 0; i < n; ++i) {
        long long value;
        std::cin >> value;
        if (i == 0 || value != previous) {
            ++distinct;
        }
        previous = value;
    }
    std::cout << distinct << '\n';
    return 0;
}
