<?php
// Preserve spaces; reverse UTF-8 code points without requiring mbstring under php -n.
$text = (string) fgets(STDIN);
if (str_ends_with($text, "\r\n")) $text = substr($text, 0, -2);
elseif (str_ends_with($text, "\n")) $text = substr($text, 0, -1);
$characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
if ($characters === false) {
    // Input is documented as text; invalid UTF-8 is not silently re-encoded.
    exit(1);
}
echo implode('', array_reverse($characters)), "\n";
