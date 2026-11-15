import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.nio.charset.StandardCharsets;

public class Main {
    public static void main(String[] args) throws Exception {
        BufferedReader reader = new BufferedReader(
            new InputStreamReader(System.in, StandardCharsets.UTF_8));
        String line = reader.readLine();
        if (line == null) {
            line = "";
        }
        int count = 0;
        for (int i = 0; i < line.length(); i++) {
            if ("aeiouAEIOU".indexOf(line.charAt(i)) >= 0) {
                count++;
            }
        }
        System.out.println(count);
    }
}
