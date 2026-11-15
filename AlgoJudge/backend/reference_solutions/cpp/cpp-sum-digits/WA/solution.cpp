#include <iostream>

// Intentional WA: the digit loop ignores every negative number.
// Counterexample: "-204\n"; expected "6\n", prints "0\n".
int main() {
    std::ios::sync_with_stdio(false);
    std::cin.tie(nullptr);

    long long number;
    if (!(std::cin >> number)) {
        return 0;
    }
    long long sum = 0;
    while (number > 0) {
        sum += number % 10;
        number /= 10;
    }
    std::cout << sum << '\n';
    return 0;
}
