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
        // WA co y: khoi tao max=0 lam sai khi ca ba so deu am.
        // Phan vi du: -1 -2 -3 in 0, dap an dung la -1.
        long maximum = 0;
        for (int i = 0; i < 3; i++) {
            maximum = Math.max(maximum, input.nextLong());
        }
        System.out.println(maximum);
    }
}
