<?php

namespace App\Modules\Enrichment\Scanning;

use App\Modules\Catalog\Models\CatalogProduct;

/**
 * Products that are the same thing in another size: "עץ אורן דו שכבתי 85X85 מ"מ (10X10)" and
 * "עץ אורן דו שכבתי 85X135 מ"מ (10X15)". The title without its sizes, numbers and model codes,
 * inside the same most specific category, is the family.
 */
final class ProductFamily
{
    private const MARKETING_TAIL = '~\s+[–—|]\s+.*$|\s+-\s+(?=\S*[\p{L}]{3,}.{25,}$).*$~u';

    private const REMOVE = [
        // Parentheses: "(10X15)", "(קצרים)", "(גוף בלבד)" stays out of the family name.
        '~\([^)]*\)~u',
        // Sizes: 20X45, 1.22X2.44, 6x40, 4.8X50.
        '~\d+(?:[.,]\d+)?\s*[xX×*]\s*\d+(?:[.,]\d+)?(?:\s*[xX×*]\s*\d+(?:[.,]\d+)?)?~u',
        // Numbers with units, including inch marks and "מ'" for meters.
        '~\d+(?:[.,]\d+)?\s*(?:מ["״\'׳]{1,2}מ|ס["״\'׳]{1,2}מ|מ["״\'׳]{1,2}ל|ק["״\'׳]{1,2}ג|מ[\'׳](?![\p{L}])|מטר|ליטר|גרם|קילו|וולט|אמפר|וואט|יח[\'׳]|יחידות|mm|cm|ml|kg|v|ah|w|l)(?![\p{L}])~iu',
        '~["״”]\s*\d+(?:[.,]\d+)?|\d+(?:[.,]\d+)?\s*["״”]~u',
        // Model codes: BL1840B, DDF484, SFMCL020B-XJ, V20.
        '~(?<![\p{L}])[A-Za-z]{1,6}\d{2,}[A-Za-z0-9\-/]*~u',
        '~\d+~u',
        // A unit left behind once its number is gone: "בפרופיל מ"מ".
        '~(?<![\p{L}])(?:מ["״\'׳]{1,2}מ|ס["״\'׳]{1,2}מ|מ["״\'׳]{1,2}ל|ק["״\'׳]{1,2}ג|מטר|ליטר|וולט|mm|cm)(?![\p{L}])~iu',
        '~במבחר\s+(?:אורכים|גוונים|גדלים|צבעים|עוביים|נפחים|מידות)|במגוון\s+(?:אורכים|גוונים|גדלים|צבעים)~u',
        '~\b(?:גוף בלבד|חבילה של|מארז|בחבילה)\b~u',
    ];

    /**
     * @return array{key: string, name: string}|null
     */
    public static function of(CatalogProduct $product): ?array
    {
        $name = TextNormalizer::clean($product->title);
        $name = (string) preg_replace(self::MARKETING_TAIL, '', $name);

        foreach (self::REMOVE as $pattern) {
            $name = (string) preg_replace($pattern, ' ', $name);
        }

        $name = trim((string) preg_replace(['~\s{2,}~u', '~^[\s\-–,.:/]+|[\s\-–,.:/]+$~u'], [' ', ''], $name));

        if (mb_strlen($name) < 4) {
            return null;
        }

        $leaf = self::leafCategory($product);

        return ['key' => substr(hash('sha256', $leaf.'|'.TextNormalizer::forMatching($name)), 0, 24), 'name' => $name];
    }

    /** The external ID of the product's most specific category, or an empty string. */
    public static function leafCategory(CatalogProduct $product): string
    {
        $best = '';
        $depth = -1;

        foreach ((array) ($product->payload['categories'] ?? []) as $category) {
            $path = (array) ($category['path'] ?? []);

            if (is_array($category) && count($path) > $depth) {
                $depth = count($path);
                $best = (string) ($category['id'] ?? '');
            }
        }

        return $best;
    }

    /** @return list<string> the product's category external IDs, most specific first */
    public static function categoriesMostSpecificFirst(CatalogProduct $product): array
    {
        $categories = array_values(array_filter((array) ($product->payload['categories'] ?? []), 'is_array'));
        usort($categories, fn (array $a, array $b): int => count((array) ($b['path'] ?? [])) <=> count((array) ($a['path'] ?? [])));

        return array_values(array_filter(array_map(fn (array $c): string => (string) ($c['id'] ?? ''), $categories)));
    }
}
