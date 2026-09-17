<?php

namespace App\Modules\Enrichment\Scanning;

/**
 * A short, exact piece of text around a match: the whole line when it is short, otherwise a
 * window of whole words. Always a substring of the text it came from, so it can be checked.
 */
final class Snippet
{
    public const WHOLE_LINE_CHARS = 70;

    public const BEFORE_CHARS = 30;

    public const AFTER_CHARS = 14;

    public static function around(string $text, int $byteStart, int $byteLength): string
    {
        $lineStart = strrpos(substr($text, 0, $byteStart), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $lineEnd = strpos($text, "\n", $byteStart + $byteLength);
        $lineEnd = $lineEnd === false ? strlen($text) : $lineEnd;

        $line = substr($text, $lineStart, $lineEnd - $lineStart);

        if (mb_strlen($line) <= self::WHOLE_LINE_CHARS) {
            return trim($line);
        }

        $matchStart = mb_strlen(substr($text, $lineStart, $byteStart - $lineStart));
        $matchLength = mb_strlen(substr($text, $byteStart, $byteLength));
        $lineLength = mb_strlen($line);

        $from = max(0, $matchStart - self::BEFORE_CHARS);
        $to = min($lineLength, $matchStart + $matchLength + self::AFTER_CHARS);

        // Whole words only: a quote never starts or ends in the middle of one.
        while ($from > 0 && ! preg_match('/\s/u', mb_substr($line, $from - 1, 1))) {
            $from--;
        }
        while ($to < $lineLength && ! preg_match('/\s/u', mb_substr($line, $to, 1))) {
            $to++;
        }

        return trim(mb_substr($line, $from, $to - $from));
    }
}
