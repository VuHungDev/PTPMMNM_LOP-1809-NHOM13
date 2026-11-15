// Intentional classroom TLE: the loop never terminates.
// Execute only in Docker with an enforced short timeout, never on the host.
// Unsigned wraparound is defined; volatile keeps the loop's observable work.
int main() {
    volatile unsigned long long counter = 0;
    for (;;) {
        ++counter;
    }
}
