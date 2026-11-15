import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.nio.charset.StandardCharsets;

public class Main {
    public static void main(String[] args) throws Exception {
        BufferedReader reader = new BufferedReader(
            new InputStreamReader(System.in, StandardCharsets.UTF_8));
        // Parse as long (not double) to preserve all digits up to 10^18.
        String digits = Long.toString(Long.parseLong(reader.readLine().trim()));
        boolean palindrome = true;
        for (int left = 0, right = digits.length() - 1; left < right; left++, right--) {
            if (digits.charAt(left) != digits.charAt(right)) {
                palindrome = false;
                break;
            }
        }
        System.out.println(palindrome ? "YES" : "NO");
    }
}
