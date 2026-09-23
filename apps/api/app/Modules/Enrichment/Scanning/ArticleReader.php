<?php

namespace App\Modules\Enrichment\Scanning;

/**
 * What an article says, read in code.
 *
 * Until now an article was only ever measured by the products it could sell, which is the right
 * question for a shop and the wrong one for a site whose articles are the thing itself. This
 * reads the article on its own terms: what it is made of, what it concludes, what question it
 * answers and who it is for.
 *
 * The store's text arrives as plain lines, so shape is all there is to go on: a short line with
 * no full stop, followed by a longer one, is a heading; a line that starts with a bullet or a
 * number is an item; a line that ends in a question mark is a question. Every marker word lives
 * in the rules rather than in this file, so the reading can be improved without touching it.
 */
final class ArticleReader
{
    /** Past this a line is prose, not a heading. */
    private const MAX_HEADING = 80;

    /** A heading has to be followed by something, and an article by more than one heading. */
    private const MIN_BODY = 40;

    private const MAX_SECTIONS = 12;

    private const MAX_TAKEAWAYS = 6;

    /** Words a minute, for saying how long a read is. */
    private const READING_SPEED = 200;

    /**
     * @param  array<string, mixed>  $rules  from ContentRules::defaults(), or a shop's own version
     * @return array{sections: list<string>, takeaways: list<array{text: string, quote: string}>,
     *               question: string|null, audience: string|null, words: int, minutes: int}
     */
    public static function read(string $title, string $body, array $rules): array
    {
        $lines = self::lines($body);
        $words = self::countWords($title."\n".$body);

        return [
            'sections' => self::sections($lines),
            'takeaways' => self::takeaways($lines, (array) ($rules['takeaway_markers'] ?? [])),
            'question' => self::question($title, $lines),
            'audience' => self::audience($lines, (array) ($rules['audience_markers'] ?? [])),
            'words' => $words,
            'minutes' => max(1, (int) ceil($words / self::READING_SPEED)),
        ];
    }

    /** @return list<string> */
    private static function lines(string $body): array
    {
        $lines = [];

        foreach (preg_split('/\R+/u', TextNormalizer::clean($body)) ?: [] as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', (string) $line));

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * The headings, in the order they appear: a short line with nothing to end it, that something
     * longer follows.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function sections(array $lines): array
    {
        $headings = [];

        foreach ($lines as $i => $line) {
            $next = $lines[$i + 1] ?? '';

            $looksLikeHeading = mb_strlen($line) <= self::MAX_HEADING
                && preg_match('/[.!;,]$/u', $line) !== 1
                && preg_match('/^[\-•*\d]/u', $line) !== 1
                && mb_strlen($next) >= self::MIN_BODY;

            if ($looksLikeHeading) {
                $headings[] = $line;
            }

            if (count($headings) >= self::MAX_SECTIONS) {
                break;
            }
        }

        return $headings;
    }

    /**
     * What the article wants the reader to take away: a listed line, or a line a marker word
     * introduces. Each keeps the line it came from, so nothing shown was not written.
     *
     * @param  list<string>  $lines
     * @param  list<string>  $markers
     * @return list<array{text: string, quote: string}>
     */
    private static function takeaways(array $lines, array $markers): array
    {
        $found = [];

        foreach ($lines as $line) {
            $listed = preg_match('/^[\-•*]\s+|^\d+[.)]\s+/u', $line) === 1;
            $marked = false;

            foreach ($markers as $marker) {
                if ($marker !== '' && mb_stripos($line, (string) $marker) === 0) {
                    $marked = true;

                    break;
                }
            }

            if (! $listed && ! $marked) {
                continue;
            }

            $text = trim((string) preg_replace('/^[\-•*]\s+|^\d+[.)]\s+/u', '', $line));

            if (mb_strlen($text) >= 12 && mb_strlen($text) <= 200) {
                $found[] = ['text' => $text, 'quote' => $line];
            }

            if (count($found) >= self::MAX_TAKEAWAYS) {
                break;
            }
        }

        return $found;
    }

    /** @param list<string> $lines */
    private static function question(string $title, array $lines): ?string
    {
        if (str_ends_with(trim($title), '?')) {
            return trim($title);
        }

        foreach ($lines as $line) {
            if (str_ends_with($line, '?') && mb_strlen($line) <= self::MAX_HEADING) {
                return $line;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $markers
     */
    private static function audience(array $lines, array $markers): ?string
    {
        foreach ($lines as $line) {
            foreach ($markers as $marker) {
                $marker = (string) $marker;

                if ($marker === '' || mb_stripos($line, $marker) === false) {
                    continue;
                }

                $after = mb_substr($line, mb_stripos($line, $marker) + mb_strlen($marker));
                $after = trim((string) preg_split('/[.,;]/u', $after)[0]);
                // A marker is usually followed by a colon or a dash before the answer starts.
                $after = trim((string) preg_replace('/^[\s:\-–—]+/u', '', $after));

                if ($after !== '' && mb_strlen($after) <= 60) {
                    return $after;
                }
            }
        }

        return null;
    }

    private static function countWords(string $text): int
    {
        return count(array_filter(preg_split('/\s+/u', trim($text)) ?: []));
    }
}
