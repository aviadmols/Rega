<?php

namespace App\Modules\Enrichment\Scanning;

/**
 * Reads numbers the way Israeli stores write them: "2,800", "1.5", "2,5", "7-1/4", "7.1/4",
 * "4½", and "½7", which is how "7½" renders once right-to-left text reorders it.
 */
final class NumberParser
{
    private const UNICODE_FRACTIONS = ['½' => 0.5, '¼' => 0.25, '¾' => 0.75, '⅛' => 0.125, '⅜' => 0.375, '⅝' => 0.625, '⅞' => 0.875, '⅓' => 1 / 3, '⅔' => 2 / 3];

    public static function parse(string $raw): ?float
    {
        $raw = trim(strtr(TextNormalizer::clean($raw), ['‑' => '-', '–' => '-', '−' => '-']));

        if ($raw === '') {
            return null;
        }

        foreach (self::UNICODE_FRACTIONS as $symbol => $fraction) {
            if (str_contains($raw, $symbol)) {
                $whole = trim(str_replace($symbol, '', $raw), " -.\u{00A0}");

                if ($whole === '') {
                    return $fraction;
                }

                return ctype_digit($whole) ? (float) $whole + $fraction : null;
            }
        }

        // "7-1/4", "7 1/4", "7.1/4": a whole number and an inch fraction. Only real fractions of
        // an inch (halves to 32nds, numerator below denominator): "15.55/18" is not one.
        if (preg_match('/^(\d+)[\s.\-]+(\d+)\/(\d+)$/', $raw, $m)) {
            return self::isInchFraction((int) $m[2], (int) $m[3]) ? (float) $m[1] + (int) $m[2] / (int) $m[3] : null;
        }

        if (preg_match('/^(\d+)\/(\d+)$/', $raw, $m)) {
            return self::isInchFraction((int) $m[1], (int) $m[2]) ? (int) $m[1] / (int) $m[2] : null;
        }

        // Thousands separators: "2,800" and "12,500.5".
        if (preg_match('/^\d{1,3}(?:,\d{3})+(?:\.\d+)?$/', $raw)) {
            return (float) str_replace(',', '', $raw);
        }

        // A decimal comma: "2,5".
        if (preg_match('/^\d+,\d{1,2}$/', $raw)) {
            return (float) str_replace(',', '.', $raw);
        }

        if (preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
            return (float) $raw;
        }

        return null;
    }

    public static function isInchFraction(int $numerator, int $denominator): bool
    {
        return in_array($denominator, [2, 4, 8, 16, 32], true) && $numerator > 0 && $numerator < $denominator;
    }

    /**
     * Every number in a piece of text, fractions included. Used to check that a value a model
     * gave actually appears in the quote it gave.
     *
     * @return list<float>
     */
    public static function all(string $text): array
    {
        $text = TextNormalizer::clean($text);
        preg_match_all('/\d+[\s.\-]+\d+\/\d+|\d+\/\d+|[½¼¾⅛⅜⅝⅞⅓⅔]?\d{1,3}(?:,\d{3})+(?:\.\d+)?[½¼¾⅛⅜⅝⅞⅓⅔]?|[½¼¾⅛⅜⅝⅞⅓⅔]?\d+(?:[.,]\d+)?[½¼¾⅛⅜⅝⅞⅓⅔]?|[½¼¾⅛⅜⅝⅞⅓⅔]/u', $text, $matches);

        $numbers = [];
        foreach ($matches[0] as $token) {
            $value = self::parse($token);
            if ($value !== null) {
                $numbers[] = $value;
            }
        }

        return $numbers;
    }
}
