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

        arsort($scores);
        $byId = $categories->keyBy('id');

        return array_values(array_map(fn (string $id): CatalogCategory => $byId->get($id), array_slice(array_keys($scores), 0, self::MAX_CATEGORIES)));
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

            // "דקים" also matches "דק", "פרגולות" also "פרגולה" through its stem.
            if (mb_strlen($word) >= 5 && preg_match('~(ים|ות)$~u', $word)) {
                $words[] = mb_substr($word, 0, -2);
            }
        }

        return array_values(array_unique($words));
    }
}
