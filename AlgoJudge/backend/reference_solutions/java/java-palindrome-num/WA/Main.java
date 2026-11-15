import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.nio.charset.StandardCharsets;

public class Main {
    public static void main(String[] args) throws Exception {
        BufferedReader reader = new BufferedReader(
            new InputStreamReader(System.in, StandardCharsets.UTF_8));
        long n = Long.parseLong(reader.readLine().trim());
        if (n % 10 == 0) {
            System.out.println("NO");
            return;
        }
        long remaining = n;
        long reversedHalf = 0;
        while (remaining > reversedHalf) {
            reversedHalf = reversedHalf * 10 + remaining % 10;
            remaining /= 10;
        }
        // WA co y: chi xu ly so chu so le; thieu remaining == reversedHalf.
        // Phan vi du: 1221 in NO, dap an dung la YES.
        boolean palindrome = remaining == reversedHalf / 10;
        System.out.println(palindrome ? "YES" : "NO");
    }
}
