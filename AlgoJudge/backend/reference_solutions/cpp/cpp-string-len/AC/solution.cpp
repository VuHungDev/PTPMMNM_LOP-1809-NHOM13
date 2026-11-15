#include <algorithm>
#include <iostream>
#include <sstream>
#include <string>

int main() {
    std::ios::sync_with_stdio(false);
    std::cin.tie(nullptr);

    std::string line;
    std::getline(std::cin, line);
    std::istringstream words(line);
    std::string word;
    std::size_t longest = 0;
    while (words >> word) {
        // A valid UTF-8 code point starts at every non-continuation byte.
        // Count characters rather than bytes (e.g. "é" is one character).
        std::size_t characters = 0;
        for (unsigned char byte : word) {
            if ((byte & 0xC0) != 0x80) ++characters;
        }
        longest = std::max(longest, characters);
    }
    std::cout << longest << '\n';
    return 0;
}
