#include <stdio.h>
#include <string.h>

/*
 * WA có chủ đích: chỉ đổi chữ thường thành hoa, không đổi chữ hoa về thường.
 * Phản ví dụ: stdin "Hello World\n" cần "hELLO wORLD\n",
 * nhưng mã này in "HELLO WORLD\n".
 */
int main(void) {
    static char line[100003];
    if (fgets(line, sizeof line, stdin) == NULL) {
        return 0;
    }

    size_t length = strlen(line);
    if (length > 0 && line[length - 1] == '\n') {
        line[--length] = '\0';
        if (length > 0 && line[length - 1] == '\r') {
            line[--length] = '\0';
        }
    }

    for (size_t i = 0; i < length; ++i) {
        if (line[i] >= 'a' && line[i] <= 'z') {
            line[i] = (char)(line[i] - 'a' + 'A');
        }
    }
    puts(line);
    return 0;
}
