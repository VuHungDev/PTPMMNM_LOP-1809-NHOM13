#include <iostream>
#include <string>

int main() {
    std::ios::sync_with_stdio(false);
    std::cin.tie(nullptr);

    // Reading the digits as text preserves the full range |N| <= 10^18.
    std::string number;
    if (!(std::cin >> number)) {
        return 0;
    }
    long long sum = 0;
    for (char digit : number) {
        if (digit >= '0' && digit <= '9') {
            sum += digit - '0';
        }
    }
    std::cout << sum << '\n';
    return 0;
}
