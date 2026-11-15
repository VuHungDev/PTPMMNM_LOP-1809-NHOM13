#include <algorithm>
#include <iostream>
#include <vector>

// Intentional WA: the exclusive end iterator omits the final array element.
// Counterexample: "3\n2 3 1\n"; expected "1 2 3\n", prints "2 3 1\n".
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
    std::sort(values.begin(), values.end() - 1);

    for (int i = 0; i < n; ++i) {
        if (i != 0) {
            std::cout << ' ';
        }
        std::cout << values[i];
    }
    std::cout << '\n';
    return 0;
}
