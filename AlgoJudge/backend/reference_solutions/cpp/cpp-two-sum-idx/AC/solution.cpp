#include <iostream>
#include <limits>
#include <map>

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
        // If target - value is outside long long, no prior input can match it.
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
        // emplace preserves the earliest index when equal values recur.
        earliest.emplace(value, j);
    }
    return 0;
}
