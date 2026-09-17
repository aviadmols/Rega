<?php

namespace App\Modules\Enrichment\Scanning;

/**
 * Store text mixes look-alike characters: Hebrew gershayim (״) and curly quotes for inches and
 * abbreviations, non-breaking hyphens, and invisible direction marks. Comparing text goes
 * through here, so "מ״מ" and 'מ"מ' are the same and a quote copied by a model still matches.
 */
final class TextNormalizer
{
    private const INVISIBLE = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}\x{00AD}]/u';

    private const LOOKALIKES = [
        '״' => '"', '”' => '"', '“' => '"', '„' => '"', '″' => '"', '‶' => '"', '＂' => '"',
        '׳' => "'", '’' => "'", '‘' => "'", '′' => "'", '`' => "'",
        '‑' => '-', '‐' => '-', '–' => '-', '—' => '-', '−' => '-', '־' => '-', '‒' => '-',
        "\u{00A0}" => ' ', "\u{2007}" => ' ', "\u{2009}" => ' ', "\u{202F}" => ' ', "\t" => ' ',
        '×' => 'x',
    ];

    /** For comparison only: lower case, one kind of quote and dash, single spaces. */
    public static function forMatching(string $text): string
    {
        $text = (string) preg_replace(self::INVISIBLE, '', $text);
        $text = strtr($text, self::LOOKALIKES);
        $text = mb_strtolower($text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Whether $needle appears in $haystack once both are normalized. Empty never matches. */
    public static function contains(string $haystack, string $needle): bool
    {
        $needle = self::forMatching($needle);

        return $needle !== '' && str_contains(self::forMatching($haystack), $needle);
    }

    /** Removes invisible direction marks and trims each line, keeping the characters otherwise. */
    public static function clean(string $text): string
    {
        $text = (string) preg_replace(self::INVISIBLE, '', $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = array_map(fn (string $line): string => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $line)), explode("\n", $text));

        return trim(implode("\n", $lines));
    }
}
