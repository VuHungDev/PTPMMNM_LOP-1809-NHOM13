#include <iostream>
#include <limits>
#include <map>

// Intentional WA: inserting before lookup can reuse index j as both indices.
// Unique-answer counterexample: "3 6\n3 2 4\n".
// Expected "1 2\n", but this program prints "0 0\n".
int main() {
    std::ios::sync_with_stdio(false);
    std::cin.tie(nullptr);

    int n;
    long long target;
    if (!(std::cin >> n >> target)) {
        return 0;
    }
    std::map<long long, int> earliest;
    for (int j = 0; j < n; ++j) {
        long long value;
        std::cin >> value;
        earliest.emplace(value, j);
        const bool complement_overflows =
            (value > 0 && target < std::numeric_limits<long long>::min() + value) ||
            (value < 0 && target > std::numeric_limits<long long>::max() + value);
        if (!complement_overflows) {
            const auto match = earliest.find(target - value);
            if (match != earliest.end()) {
                std::cout << match->second << ' ' << j << '\n';
                return 0;
            }
        }
    }
    return 0;
}
