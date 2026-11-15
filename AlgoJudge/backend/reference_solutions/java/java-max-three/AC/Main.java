import java.io.BufferedInputStream;
import java.io.IOException;

public class Main {
    private static class FastInput {
        private final BufferedInputStream input = new BufferedInputStream(System.in);

        long nextLong() throws IOException {
            int c;
            do {
                c = input.read();
            } while (c <= ' ' && c != -1);
            boolean negative = c == '-';
            if (negative || c == '+') {
                c = input.read();
            }
            long value = 0;
            while (c > ' ') {
                value = value * 10 - (c - '0');
                c = input.read();
            }
            return negative ? value : -value;
        }
    }

    public static void main(String[] args) throws Exception {
        FastInput input = new FastInput();
        long a = input.nextLong();
        long b = input.nextLong();
        long c = input.nextLong();
        System.out.println(Math.max(a, Math.max(b, c)));
    }
}
