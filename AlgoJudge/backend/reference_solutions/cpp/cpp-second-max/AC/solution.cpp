#include <algorithm>
#include <iostream>

int main() {
    std::ios::sync_with_stdio(false);
    std::cin.tie(nullptr);

    int n;
    if (!(std::cin >> n)) {
        return 0;
    }
    long long largest;
    long long second;
    std::cin >> largest >> second;
    if (second > largest) {
        std::swap(largest, second);
    }

    for (int i = 2; i < n; ++i) {
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
