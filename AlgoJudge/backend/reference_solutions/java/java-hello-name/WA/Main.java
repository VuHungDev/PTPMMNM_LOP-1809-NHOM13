import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.nio.charset.StandardCharsets;

public class Main {
    public static void main(String[] args) throws Exception {
        BufferedReader reader = new BufferedReader(
            new InputStreamReader(System.in, StandardCharsets.UTF_8));
        String name = reader.readLine().trim();
        // WA co y: thieu dau phay trong mau loi chao bat buoc.
        // Phan vi du: An -> Hello An!, dung phai la Hello, An!
        System.out.println("Hello " + name + "!");
    }
}
