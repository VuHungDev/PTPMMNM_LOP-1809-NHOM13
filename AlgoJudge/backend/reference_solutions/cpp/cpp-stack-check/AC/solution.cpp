#include <iostream>
#include <string>
#include <vector>

int main() {
    std::ios::sync_with_stdio(false);
    std::cin.tie(nullptr);

    std::string brackets;
    std::getline(std::cin, brackets);
    if (!brackets.empty() && brackets.back() == '\r') {
        brackets.pop_back();
    }
    std::vector<char> stack;
    for (char bracket : brackets) {
        if (bracket == '(' || bracket == '[' || bracket == '{') {
            stack.push_back(bracket);
        } else {
            const char opening = bracket == ')' ? '(' : (bracket == ']' ? '[' : '{');
            if (stack.empty() || stack.back() != opening) {
                std::cout << "NO\n";
                return 0;
            }
            stack.pop_back();
        }
    }
    std::cout << (stack.empty() ? "YES\n" : "NO\n");
    return 0;
}
