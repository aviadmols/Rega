<?php

namespace App\Modules\Enrichment\Scanning;

/**
 * Shortens product text before a model sees it, in code: drops "click here" lines, lines that
 * repeat the title or each other, and, past the budget, the least informative lines first.
 * Lines with numbers or "label: value" pairs are kept before prose.
 */
final class TextCondenser
{
    private const BOILERPLATE = '~לחץ כאן|לחצו כאן|למעבר לאתר|לרכישת מוצרי|לרשימת כל|לכל המוצרים|click here|read more|קרא עוד|קראו עוד|שתפו את|share this~iu';

    /**
     * @param  list<string>  $sections  title first, then the rest in order of trust
     * @param  list<string>  $boilerplate  normalized lines repeated across the catalog (see Boilerplate)
     * @param  bool  $headingsWithContent  past the budget, keep a heading ("שימושים:") only with the line after it,
     *                                     so short headings do not take the place of what they introduce
     * @return array{text: string, truncated: bool, source_chars: int}
     */
    public function condense(array $sections, int $maxChars, array $boilerplate = [], bool $headingsWithContent = false): array
    {
        $lines = [];
        $seen = [];
        $drop = array_fill_keys($boilerplate, true);
        $sourceChars = 0;

        foreach ($sections as $section) {
            $section = TextNormalizer::clean($section);
            $sourceChars += mb_strlen($section);

            foreach (explode("\n", $section) as $line) {
                $line = self::trimBullets($line);

                if ($line === '' || preg_match(self::BOILERPLATE, $line)) {
                    continue;
                }

                $key = TextNormalizer::forMatching($line);

                if (isset($seen[$key]) || isset($drop[$key]) || $this->containedInEarlier($key, $seen)) {
                    continue;
                }

                $seen[$key] = true;
                $lines[] = $line;
            }
        }

        $full = implode("\n", $lines);

        if (mb_strlen($full) <= $maxChars) {
            return ['text' => $full, 'truncated' => false, 'source_chars' => $sourceChars];
        }

        // Over budget: the title, then informative lines, then prose, each in original order.
        $keep = [0 => true];
        $used = mb_strlen($lines[0] ?? '');

        foreach ([true, false] as $informative) {
            foreach ($lines as $i => $line) {
                if (isset($keep[$i]) || $this->isInformative($line) !== $informative || ($headingsWithContent && self::isHeading($line))) {
                    continue;
                }

                $length = mb_strlen($line) + 1;

                if ($used + $length > $maxChars) {
                    continue;
                }

                $keep[$i] = true;
                $used += $length;
            }
        }

        if ($headingsWithContent) {
            foreach ($lines as $i => $line) {
                if (! isset($keep[$i]) && self::isHeading($line) && isset($keep[$i + 1]) && $used + mb_strlen($line) + 1 <= $maxChars) {
                    $keep[$i] = true;
                    $used += mb_strlen($line) + 1;
                }
            }
        }

        ksort($keep);

        return [
            'text' => implode("\n", array_map(fn (int $i): string => $lines[$i], array_keys($keep))),
            'truncated' => true,
            'source_chars' => $sourceChars,
        ];
    }

    /** @return list<string> the normalized lines of a section, the same way condense() sees them */
    public static function lineKeys(string $section): array
    {
        $keys = [];

        foreach (explode("\n", TextNormalizer::clean($section)) as $line) {
            $line = self::trimBullets($line);

            if ($line !== '') {
                $keys[] = TextNormalizer::forMatching($line);
            }
        }

        return $keys;
    }

    /**
     * Removes bullets and spaces at both ends. Not trim(): its character list is bytes, and the
     * bytes of "•" also occur inside Hebrew letters, which it would cut in half.
     */
    private static function trimBullets(string $line): string
    {
        return (string) preg_replace('/^[\s•·\-*]+|[\s•·\-*]+$/u', '', $line);
    }

    /** A short line that only introduces what follows: "יתרונות המוצר:". */
    private static function isHeading(string $line): bool
    {
        return mb_strlen($line) <= 60 && (bool) preg_match('~:\s*$~u', $line);
    }

    private function isInformative(string $line): bool
    {
        return (bool) preg_match('~\d|:\s*\S~u', $line) && mb_strlen($line) <= 200;
    }

    /** A short line already said inside a longer earlier one, such as a title repeated in a sentence. */
    private function containedInEarlier(string $key, array $seen): bool
    {
        if (mb_strlen($key) > 120) {
            return false;
        }

        foreach (array_keys($seen) as $earlier) {
            if (mb_strlen((string) $earlier) > mb_strlen($key) && str_contains((string) $earlier, $key)) {
                return true;
            }
        }

        return false;
    }
}
