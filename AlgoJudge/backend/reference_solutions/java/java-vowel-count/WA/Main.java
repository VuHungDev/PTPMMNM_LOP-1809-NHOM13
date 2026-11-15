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
        // WA co y: quen nguyen am viet hoa, trai yeu cau khong phan biet hoa thuong.
        // Phan vi du: AEIOU in 0, dap an dung la 5.
        for (int i = 0; i < line.length(); i++) {
            if ("aeiou".indexOf(line.charAt(i)) >= 0) {
                count++;
            }
        }
        System.out.println(count);
    }
}
