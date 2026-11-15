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
        long target = input.nextLong();
        long[] values = new long[n];
        for (int i = 0; i < n; i++) {
            values[i] = input.nextLong();
        }
        int left = 0;
        int right = n - 1;
        int answer = -1;
        while (left <= right) {
            int middle = left + (right - left) / 2;
            if (values[middle] == target) {
                answer = middle;
                break;
            } else if (values[middle] < target) {
                left = middle + 1;
            } else {
                right = middle - 1;
            }
        }
        System.out.println(answer);
    }
}
