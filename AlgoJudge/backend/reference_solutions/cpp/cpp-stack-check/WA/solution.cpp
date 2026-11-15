#include <iostream>
#include <string>

// Intentional WA: equal opening/closing counts do not guarantee correct nesting.
// Counterexample: "([)]\n"; expected "NO\n", prints "YES\n".
int main() {
    std::ios::sync_with_stdio(false);
    std::cin.tie(nullptr);

    std::string brackets;
    std::getline(std::cin, brackets);
    long long round = 0;
    long long square = 0;
    long long curly = 0;
    for (char bracket : brackets) {
        if (bracket == '(') {
            ++round;
        } else if (bracket == ')') {
            --round;
        } else if (bracket == '[') {
            ++square;
        } else if (bracket == ']') {
            --square;
        } else if (bracket == '{') {
            ++curly;
        } else if (bracket == '}') {
            --curly;
        }
    }
    std::cout << (round == 0 && square == 0 && curly == 0 ? "YES\n" : "NO\n");
    return 0;
}
