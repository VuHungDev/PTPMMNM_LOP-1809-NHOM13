import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.nio.charset.StandardCharsets;

public class Main {
    public static void main(String[] args) throws Exception {
        BufferedReader reader = new BufferedReader(
            new InputStreamReader(System.in, StandardCharsets.UTF_8));
        long n = Long.parseLong(reader.readLine().trim());
        long k = n / 2;
        // 2 + 4 + ... + 2*k = k*(k+1); long covers N <= 10^9.
        System.out.println(k * (k + 1));
    }
}
