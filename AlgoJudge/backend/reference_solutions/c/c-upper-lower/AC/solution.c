#include <ctype.h>
#include <locale.h>
#include <stdio.h>
#include <wchar.h>
#include <wctype.h>

int main(void) {
    /* The Docker runtime provides C.UTF-8. Decode characters, not individual bytes. */
    if (setlocale(LC_CTYPE, "C.UTF-8") == NULL && setlocale(LC_CTYPE, "") == NULL) {
        return 1;
    }
    wint_t character;
    while ((character = fgetwc(stdin)) != WEOF && character != L'\n' && character != L'\r') {
        if (iswlower(character)) character = towupper(character);
        else if (iswupper(character)) character = towlower(character);
        if (fputwc(character, stdout) == WEOF) return 1;
    }
    fputwc(L'\n', stdout);
    return 0;
}
