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
        // WA co y: bo phan tu dau mang do nham diem bat dau cong don.
        // Phan vi du: N=4, mang 1 2 3 4 in 9 thay vi 10.
        for (int i = 0; i < n; i++) {
            long value = input.nextLong();
            if (i > 0) {
                sum += value;
            }
        }
        System.out.println(sum);
    }
}
