#include <iostream>
#include <vector>

// Intentional WA: subtracts prefix[left], excluding the left endpoint.
// Counterexample: "3 2\n2 4 8\n1 3\n2 2\n".
// Expected "14\n4\n", prints "12\n0\n".
int main() {
    std::ios::sync_with_stdio(false);
    std::cin.tie(nullptr);

    int n;
    int queries;
    if (!(std::cin >> n >> queries)) {
        return 0;
    }
    std::vector<long long> prefix(n + 1, 0);
    for (int i = 1; i <= n; ++i) {
        long long value;
        std::cin >> value;
        prefix[i] = prefix[i - 1] + value;
    }
    for (int query = 0; query < queries; ++query) {
        int left;
        int right;
        std::cin >> left >> right;
        std::cout << prefix[right] - prefix[left] << '\n';
    }
    return 0;
}
