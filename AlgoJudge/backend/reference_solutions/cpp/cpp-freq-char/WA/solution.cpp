#include <array>
#include <iostream>
#include <string>

// Intentional WA: replacing on ties chooses the lexicographically largest letter.
// Counterexample: "aabb\n"; expected "a\n", prints "b\n".
int main() {
    std::ios::sync_with_stdio(false);
    std::cin.tie(nullptr);

    std::string text;
    if (!(std::cin >> text)) {
        return 0;
    }
    std::array<long long, 26> counts{};
    for (char character : text) {
        ++counts[character - 'a'];
    }
    int best = 0;
    for (int i = 1; i < 26; ++i) {
        if (counts[i] >= counts[best]) {
            best = i;
        }
    }
    std::cout << static_cast<char>('a' + best) << '\n';
    return 0;
}
