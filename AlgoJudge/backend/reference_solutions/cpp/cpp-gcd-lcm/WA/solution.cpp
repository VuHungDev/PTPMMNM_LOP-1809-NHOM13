#include <iostream>
#include <numeric>

// Intentional WA: the product equals the LCM only for coprime inputs.
// Counterexample: "6 8\n"; expected "2\n24\n", prints "2\n48\n".
int main() {
    std::ios::sync_with_stdio(false);
    std::cin.tie(nullptr);

    long long a;
    long long b;
    if (!(std::cin >> a >> b)) {
        return 0;
    }
    const long long gcd = std::gcd(a, b);
    const long long lcm = a * b;
    std::cout << gcd << '\n' << lcm << '\n';
    return 0;
}
