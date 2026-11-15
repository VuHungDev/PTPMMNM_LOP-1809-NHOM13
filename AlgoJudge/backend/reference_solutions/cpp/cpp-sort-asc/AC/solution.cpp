#include <algorithm>
#include <iostream>
#include <vector>

int main() {
    std::ios::sync_with_stdio(false);
    std::cin.tie(nullptr);

    int n;
    if (!(std::cin >> n)) {
        return 0;
    }
    std::vector<long long> values(n);
    for (long long& value : values) {
        std::cin >> value;
    }
    std::sort(values.begin(), values.end());

    for (int i = 0; i < n; ++i) {
        if (i != 0) {
            std::cout << ' ';
        }
        std::cout << values[i];
    }
    std::cout << '\n';
    return 0;
}
