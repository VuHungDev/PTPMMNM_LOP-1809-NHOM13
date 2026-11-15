#include <iostream>
#include <numeric>

int main() {
    std::ios::sync_with_stdio(false);
    std::cin.tie(nullptr);

    long long a;
    long long b;
    if (!(std::cin >> a >> b)) {
        return 0;
    }
    const long long gcd = std::gcd(a, b);
    const long long lcm = (a / gcd) * b;
    std::cout << gcd << '\n' << lcm << '\n';
    return 0;
}
