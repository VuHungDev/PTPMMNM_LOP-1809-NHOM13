#include <stdexcept>

// Intentional classroom RE: an uncaught exception terminates the program.
// This is not a solution; execute only in the isolated Docker judge.
int main() {
    throw std::runtime_error("Intentional classroom runtime-error example");
}
