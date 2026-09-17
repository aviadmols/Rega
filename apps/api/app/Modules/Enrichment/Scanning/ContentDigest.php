<?php

namespace App\Modules\Enrichment\Scanning;

use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use Illuminate\Support\Collection;

/**
 * An article packed for a model: the text condensed, and the store categories whose names
 * appear in it, found by code. The model only says which of those really fit.
 */
final class ContentDigest
{
    private const MAX_CATEGORIES = 8;

    private const TITLE_WEIGHT = 3;

    private const MIN_SCORE = 2;

    /** Hebrew one-letter prefixes: "והדק", "בדק", "לדק" all contain "דק". */
    private const PREFIXES = '[והבלמשכ]{0,2}';

    private const STOPWORDS = ['של', 'עם', 'או', 'את', 'על', 'לכל', 'כלי', 'מוצרי', 'אביזרים', 'אביזרי', 'and', 'for', 'the'];

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        public readonly array $request,
        public readonly array $context,
        public readonly string $inputHash,
    ) {}

    /** @param Collection<int, CatalogCategory> $categories the shop's active categories */
    public static function build(CatalogContent $content, Collection $categories, int $maxTextChars, string $promptHash): self
    {
        $condensed = (new TextCondenser)->condense([$content->title, (string) $content->body], $maxTextChars);
        $matches = self::matchCategories($content->title, $condensed['text'], $categories);

        $candidates = [];
        $map = [];
        foreach (array_values($matches) as $i => $category) {
            $id = 'k'.($i + 1);
            $candidates[] = [$id, $category->pathLabel()];
            $map[$id] = ['external_id' => $category->external_id, 'path' => $category->pathLabel()];
        }

        $request = array_filter([
            'id' => $content->external_id,
            'title' => $content->title,
            'excerpt' => mb_strimwidth((string) $content->excerpt, 0, 300, '…'),
            'text' => $condensed['text'],
            'categories' => $candidates,
        ], fn ($value): bool => $value !== null && $value !== '' && $value !== []);

        $inputHash = hash('sha256', $promptHash.'|'.json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return new self($request, ['content_id' => $content->id, 'categories' => $map, 'text' => $condensed['text']], $inputHash);
    }

    /**
     * @param  Collection<int, CatalogCategory>  $categories
     * @return list<CatalogCategory>
     */
    public static function matchCategories(string $title, string $text, Collection $categories): array
    {
        $title = TextNormalizer::forMatching($title);
        $text = TextNormalizer::forMatching($text);
        $scores = [];

        foreach ($categories as $category) {
            $score = 0;

            foreach (self::words($category->name) as $word) {
                $pattern = '~(?<![\p{L}])'.self::PREFIXES.preg_quote($word, '~').'(?![\p{L}])~u';
                $score += self::TITLE_WEIGHT * min(3, (int) preg_match_all($pattern, $title));
                $score += min(5, (int) preg_match_all($pattern, $text));
            }

            if ($score >= self::MIN_SCORE) {
                $scores[$category->id] = $score;
            }
        }

        $byId = $categories->keyBy('id');
        $ids = array_keys($scores);

        // A fixed order for equal scores: the more specific category first, then by name. Without
        // it the order follows the database, and the same article gets a different request (and
        // request ID) on SQLite and on Postgres.
        usort($ids, function (string $a, string $b) use ($scores, $byId): int {
            return [$scores[$b], count($byId->get($b)->path), $byId->get($a)->pathLabel(), $byId->get($a)->external_id]
                <=> [$scores[$a], count($byId->get($a)->path), $byId->get($b)->pathLabel(), $byId->get($b)->external_id];
        });

        return array_values(array_map(fn (string $id): CatalogCategory => $byId->get($id), array_slice($ids, 0, self::MAX_CATEGORIES)));
    }

    /** Hebrew letters change shape at the end of a word: "עצ" is written "עץ". */
    private static function withFinalLetter(string $word): string
    {
        $finals = ['כ' => 'ך', 'מ' => 'ם', 'נ' => 'ן', 'פ' => 'ף', 'צ' => 'ץ'];
        $last = mb_substr($word, -1);

        return isset($finals[$last]) ? mb_substr($word, 0, -1).$finals[$last] : $word;
    }

    /** @return list<string> the meaningful words of a category name, with plural endings dropped */
    private static function words(string $name): array
    {
        $words = [];

        foreach (preg_split('~[^\p{L}\d\']+~u', TextNormalizer::forMatching($name)) ?: [] as $word) {
            if (mb_strlen($word) < 2 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }

            $words[] = $word;

            // Construct state: "תחזוקה" is written "תחזוקת" in "מוצרי תחזוקת עץ".
            if (mb_strlen($word) >= 4 && mb_substr($word, -1) === 'ה') {
                $words[] = mb_substr($word, 0, -1).'ת';
            }

            // Plurals also match the singular: "דקים" matches "דק", "עצים" matches "עץ" (with its
            // final letter), "פרגולות" matches "פרגולה".
            if (mb_strlen($word) >= 4 && preg_match('~(ים|ות)$~u', $word, $suffix)) {
                $stem = mb_substr($word, 0, -2);

                if (mb_strlen($stem) >= 2) {
                    $words[] = self::withFinalLetter($stem);

                    if ($suffix[1] === 'ות') {
                        $words[] = $stem.'ה';
                    }
                }
            }
        }

        return array_values(array_unique($words));
    }
}
