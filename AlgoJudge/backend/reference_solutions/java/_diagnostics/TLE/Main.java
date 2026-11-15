// INTENTIONAL CLASSROOM DIAGNOSTIC: TLE (time limit exceeded).
// Infinite CPU loop: run only inside Docker with an enforced short time limit.
// Never run on the host. No stdout output, subprocesses, or memory growth.
public class Main {
    private static volatile long counter = 0;

    public static void main(String[] args) {
        while (true) {
            counter = (counter + 1) & 0x7fffffffL;
        }
    }
}
