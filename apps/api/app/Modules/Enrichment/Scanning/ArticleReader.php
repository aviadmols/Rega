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

    /** Shorter than this, what follows a phrase is the end of a sentence, not a point. */
    private const MIN_CLAUSE = 24;

    /** More than two "what is X" chips stops being a shortcut and becomes a list. */
    private const MAX_SUBJECTS = 2;

    /** Words a minute, for saying how long a read is. */
    private const READING_SPEED = 200;

    /**
     * @param  array<string, mixed>  $rules  from ContentRules::defaults(), or a shop's own version
     * @return array{sections: list<string>, takeaways: list<array{text: string, quote: string}>,
     *               question: string|null, audience: string|null, words: int, minutes: int,
     *               questions: list<array{kind: string, term?: string, count?: int}>}
     */
    public static function read(string $title, string $body, array $rules): array
    {
        $lines = self::lines($body);
        $words = self::countWords($title."\n".$body);

        $sections = self::sections($lines);
        $takeaways = self::takeaways($lines, $rules);
        $audience = self::audience($lines, (array) ($rules['audience_markers'] ?? []));

        return [
            'sections' => $sections,
            'takeaways' => $takeaways,
            'question' => self::question($title, $lines),
            'audience' => $audience,
            'words' => $words,
            'minutes' => max(1, (int) ceil($words / self::READING_SPEED)),
            'questions' => self::worthAsking($sections, $takeaways, $audience, $lines, $rules),
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

    /** A sentence without what only ends it, so two wordings of one point can be compared. */
    private static function bare(string $text): string
    {
        return trim((string) preg_replace('/[.!?,;:־–—\s]+$/u', '', $text));
    }

    /**
     * Whether a line says any of these.
     *
     * @param  list<string>  $phrases
     */
    private static function mentions(string $line, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if ($phrase !== '' && mb_stripos($line, (string) $phrase) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a point has already been made.
     *
     * An article that repeats "the recommendation is to consult an expert" three times, each time
     * with a few more words, has made one point, and a shopper should be shown it once. The longer
     * wording wins, because it is the one that says the whole thing.
     *
     * @param  list<array{text: string, quote: string}>  $found
     */
    private static function saidAlready(array &$found, string $text): bool
    {
        $bare = self::bare($text);

        foreach ($found as $i => $earlier) {
            $was = self::bare($earlier['text']);

            if (mb_stripos($was, $bare) !== false) {
                return true;
            }

            if (mb_stripos($bare, $was) !== false) {
                $found[$i]['text'] = $text;

                return true;
            }
        }

        return false;
    }

    /**
     * The part of a line a phrase introduces: from the phrase to the end of the line.
     *
     * Wherever the phrase stands, including at the very opening — a marker list cannot be relied
     * on to cover every phrase, and a point introduced in the first three words is still the
     * point. Only when what follows is long enough to say something on its own.
     *
     * @param  list<string>  $phrases
     */
    private static function clause(string $line, array $phrases): ?string
    {
        foreach ($phrases as $phrase) {
            $phrase = trim((string) $phrase);

            if ($phrase === '') {
                continue;
            }

            $at = mb_stripos($line, $phrase);

            if ($at === false) {
                continue;
            }

            $clause = trim(mb_substr($line, $at));

            if (mb_strlen($clause) >= self::MIN_CLAUSE) {
                return $clause;
            }
        }

        return null;
    }

    /**
     * What the article wants the reader to take away: a listed line, or a line a marker word
     * introduces. Each keeps the line it came from, so nothing shown was not written.
     *
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $rules
     * @return list<array{text: string, quote: string}>
     */
    private static function takeaways(array $lines, array $rules): array
    {
        $markers = (array) ($rules['takeaway_markers'] ?? []);
        $phrases = (array) ($rules['takeaway_phrases'] ?? []);
        $skip = (array) ($rules['skip_lines'] ?? []);
        $headings = array_flip(self::sections($lines));
        $found = [];

        foreach ($lines as $line) {
            // A heading names what follows; it is not itself the point. Nor is the furniture of
            // the site, or a line telling the reader to go and buy somewhere else.
            if (isset($headings[$line]) || self::mentions($line, $skip)) {
                continue;
            }

            $listed = preg_match('/^[\-•*]\s+|^\d+[.)]\s+/u', $line) === 1;
            $marked = false;

            foreach ($markers as $marker) {
                if ($marker !== '' && mb_stripos($line, (string) $marker) === 0) {
                    $marked = true;

                    break;
                }
            }

            // A line that says its conclusion halfway through: the takeaway is that half.
            $clause = $listed || $marked ? null : self::clause($line, $phrases);

            if (! $listed && ! $marked && $clause === null) {
                continue;
            }

            $text = $clause ?? trim((string) preg_replace('/^[\-•*]\s+|^\d+[.)]\s+/u', '', $line));

            if (mb_strlen($text) >= 12 && mb_strlen($text) <= 200 && ! self::saidAlready($found, $text)) {
                $found[] = ['text' => $text, 'quote' => $line];
            }

            if (count($found) >= self::MAX_TAKEAWAYS) {
                break;
            }
        }

        return $found;
    }

    /**
     * What is worth asking about this article, as things rather than as sentences.
     *
     * A reader is not shown a text box and left to invent a question; they are shown the two or
     * three questions this particular article can answer well. Which ones those are is decided
     * here, from what the reading already found — the points, the subjects the piece keeps
     * returning to, who it is for. The wording is not decided here: these are kinds and terms,
     * turned into a sentence in the reader's own language when the widget asks for them.
     *
     * A subject only counts when the article says it more than once. A word that appears in a
     * heading and nowhere else is a heading, not what the piece is about.
     *
     * @param  list<string>  $sections
     * @param  list<array{text: string, quote: string}>  $takeaways
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $rules
     * @return list<array{kind: string, term?: string, count?: int}>
     */
    private static function worthAsking(array $sections, array $takeaways, ?string $audience, array $lines, array $rules): array
    {
        // Summing up is what a reader wants first, and it is the one question every article can
        // answer, so it is always there and always first.
        $asks = [['kind' => 'summary']];

        if (count($takeaways) >= 2) {
            $asks[] = ['kind' => 'points', 'count' => count($takeaways)];
        }

        $body = mb_strtolower(implode(' ', $lines));
        $skip = array_map('mb_strtolower', (array) ($rules['not_subjects'] ?? []));
        $terms = 0;

        foreach ($sections as $heading) {
            if ($terms >= self::MAX_SUBJECTS) {
                break;
            }

            $term = trim((string) preg_replace('/[?:.!،,]+$/u', '', $heading));
            $words = count(preg_split('/\s+/u', $term) ?: []);

            if ($words > 2 || mb_strlen($term) < 3 || mb_strlen($term) > 24) {
                continue;
            }

            if (in_array(mb_strtolower($term), $skip, true)) {
                continue;
            }

            // Said more than once: the article keeps coming back to it, so it is a subject.
            if (mb_substr_count($body, mb_strtolower($term)) < 2) {
                continue;
            }

            $asks[] = ['kind' => 'term', 'term' => $term];
            $terms++;
        }

        if ($audience !== null) {
            $asks[] = ['kind' => 'audience'];
        }

        return $asks;
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
