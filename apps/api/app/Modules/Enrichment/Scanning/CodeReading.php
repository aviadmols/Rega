<?php

namespace App\Modules\Enrichment\Scanning;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;

/**
 * Everything code can settle about a product without a model:
 *
 *   brand        from the brand field, a brand attribute, the title or the description
 *   family       the same product in other sizes (title without sizes, same category)
 *   type         the product type the store category stands for, when the vocabulary maps it
 *   category_choices  choice values the category stands for ("מוקצע לא מחוטא" is untreated)
 *   specs        sizes the vocabulary reads from the title ("20X45 מ"מ" is 20 thick, 45 wide)
 *   pack_count   "100 יח'" in the title
 *   choices      what a shopper must choose on the product page (a length, a volume)
 *   price_unit   the store's price note ("מחיר למטר")
 *   rental       a rental service, not a product to buy
 */
final class CodeReading
{
    public const VERSION = 1;

    private const RENTAL = '~^\s*השכרת|להשכרה~u';

    private const MAX_CHOICE_VALUES = 12;

    /** @return array<string, mixed> */
    public static function read(CatalogProduct $product, BrandResolver $brands, ?VocabularyDefinition $vocabulary): array
    {
        $categories = ProductFamily::categoriesMostSpecificFirst($product);
        $type = $vocabulary?->typeForCategories($categories);

        // A category holds what the store put there: "זוית עץ אורן" sits with planed boards. When
        // the title names a different type, code does not decide; the model reads it.
        if ($type !== null && $vocabulary !== null) {
            $hints = ProductDigest::typeHints($product->title, $vocabulary);

            if ($hints !== [] && ! in_array($type, $hints, true)) {
                $type = null;
            }
        }

        return array_filter([
            'version' => self::VERSION,
            'vocabulary' => $vocabulary?->key(),
            'brand' => $brands->resolve($product),
            'family' => ProductFamily::of($product),
            'type' => $type === null ? null : ['key' => $type, 'source' => 'category'],
            'category_choices' => $vocabulary?->choicesForCategories($categories) ?: null,
            'specs' => $vocabulary?->titleSpecs($product->title, $type) ?: null,
            'pack_count' => self::packCount($product->title),
            'choices' => self::choices($product) ?: null,
            'price_unit' => self::priceUnit($product),
            'rental' => preg_match(self::RENTAL, $product->title) === 1 ? true : null,
        ], fn ($value): bool => $value !== null);
    }

    private static function packCount(string $title): ?int
    {
        foreach ((new MeasurementScanner)->scan($title) as $measurement) {
            if ($measurement->dimension === 'count' && $measurement->value >= 2) {
                return (int) $measurement->value;
            }
        }

        return null;
    }

    /** @return list<array{name: string, values: list<string>}> */
    private static function choices(CatalogProduct $product): array
    {
        $choices = [];

        foreach ($product->storeAttributes() as $attribute) {
            if ($attribute['for_variations'] && count($attribute['values']) > 1) {
                $choices[] = ['name' => $attribute['name'], 'values' => array_slice($attribute['values'], 0, self::MAX_CHOICE_VALUES)];
            }
        }

        return $choices;
    }

    private static function priceUnit(CatalogProduct $product): ?string
    {
        $note = trim(strip_tags((string) ($product->payload['meta']['price_text'] ?? '')));

        return $note === '' ? null : mb_substr($note, 0, 40);
    }
}
