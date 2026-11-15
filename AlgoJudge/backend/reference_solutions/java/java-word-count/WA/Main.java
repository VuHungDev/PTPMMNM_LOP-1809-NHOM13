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
        // WA co y: so tu = so ky tu trang + 1, khong bo cac tu rong.
        // Phan vi du: "a  b" in 3, dap an dung la 2.
        int words = line.isEmpty() ? 0 : 1;
        for (int i = 0; i < line.length(); i++) {
            char ch = line.charAt(i);
            if (Character.isWhitespace(ch) || Character.isSpaceChar(ch)) {
                words++;
            }
        }
        System.out.println(words);
    }
}