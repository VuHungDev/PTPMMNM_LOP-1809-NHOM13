import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.nio.charset.StandardCharsets;

public class Main {
    public static void main(String[] args) throws Exception {
        BufferedReader reader = new BufferedReader(
            new InputStreamReader(System.in, StandardCharsets.UTF_8));
        long n = Long.parseLong(reader.readLine().trim());
        // WA co y: chi cong so chan nho hon N, bo N neu N chan.
        // Phan vi du: N=10 in 20, dap an dung la 30.
        long k = (n - 1) / 2;
        System.out.println(k * (k + 1));
    }
}
