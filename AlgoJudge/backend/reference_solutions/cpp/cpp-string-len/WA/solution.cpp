#include <iostream>
#include <sstream>
#include <string>

// Intentional WA: overwrites the maximum instead of comparing word lengths.
// Counterexample: "longword hi\n"; expected "8\n", prints "2\n".
int main() {
    std::ios::sync_with_stdio(false);
    std::cin.tie(nullptr);

    std::string line;
    std::getline(std::cin, line);
    std::istringstream words(line);
    std::string word;
    std::size_t longest = 0;
    while (words >> word) {
        longest = word.size();
    }
    std::cout << longest << '\n';
    return 0;
}
