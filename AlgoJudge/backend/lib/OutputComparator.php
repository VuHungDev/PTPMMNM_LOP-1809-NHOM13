<?php
// Pure stdout comparison: usable in offline tests without bootstrap or a database.
final class OutputComparator
{
    public static function normalize(string $out): string
    {
        $out = str_replace("\r\n", "\n", trim($out));
        return implode("\n", array_map('rtrim', explode("\n", $out)));
    }

    public static function matches(string $actual, string $expected): bool
    {
        // Never remove prompts, labels or other text: they are part of stdout.
        return self::normalize($actual) === self::normalize($expected);
    }
}
