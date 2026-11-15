#include <iostream>
#include <set>

int main() {
    std::ios::sync_with_stdio(false);
    std::cin.tie(nullptr);

    int n;
    if (!(std::cin >> n)) {
        return 0;
    }
    std::set<long long> distinct;
    for (int i = 0; i < n; ++i) {
        long long value;
        std::cin >> value;
        distinct.insert(value);
    }
    std::cout << distinct.size() << '\n';
    return 0;
}
