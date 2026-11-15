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
        int n = (int) input.nextLong();
        long sum = 0;
        for (int row = 0; row < n; row++) {
            for (int column = 0; column < n; column++) {
                long value = input.nextLong();
                if (row == column) {
                    sum += value;
                }
            }
        }
        System.out.println(sum);
    }
}
