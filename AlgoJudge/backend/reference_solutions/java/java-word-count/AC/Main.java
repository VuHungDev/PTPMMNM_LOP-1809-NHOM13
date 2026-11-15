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
        int words = 0;
        boolean inWord = false;
        for (int i = 0; i < line.length(); i++) {
            char ch = line.charAt(i);
            if (Character.isWhitespace(ch) || Character.isSpaceChar(ch)) {
                inWord = false;
            } else if (!inWord) {
                words++;
                inWord = true;
            }
        }
        System.out.println(words);
    }
}
