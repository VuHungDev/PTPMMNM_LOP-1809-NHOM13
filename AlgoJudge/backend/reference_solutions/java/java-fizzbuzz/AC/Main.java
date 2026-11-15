import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.nio.charset.StandardCharsets;

public class Main {
    public static void main(String[] args) throws Exception {
        BufferedReader reader = new BufferedReader(
            new InputStreamReader(System.in, StandardCharsets.UTF_8));
        int n = Integer.parseInt(reader.readLine().trim());
        StringBuilder output = new StringBuilder();
        for (int i = 1; i <= n; i++) {
            if (i % 15 == 0) {
                output.append("FizzBuzz");
            } else if (i % 3 == 0) {
                output.append("Fizz");
            } else if (i % 5 == 0) {
                output.append("Buzz");
            } else {
                output.append(i);
            }
            output.append('\n');
        }
        System.out.print(output);
    }
}
