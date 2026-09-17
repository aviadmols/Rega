<?php

namespace App\Modules\Enrichment\Scanning;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use Illuminate\Support\Collection;

/**
 * What the highlights writer gets for one product: its condensed text and the facts already shown
 * next to it, as short lines, so it writes what the spec list does not say.
 */
final class HighlightDigest
{
    /**
     * @param  array<string, mixed>  $request  what the model receives
     * @param  array<string, mixed>  $context  what the importer needs to check the answer
     */
    private function __construct(
        public readonly array $request,
        public readonly array $context,
        public readonly string $inputHash,
    ) {}

    /**
     * @param  list<string>  $boilerplate  normalized lines repeated across the branch, dropped
     * @param  list<string>  $known  from known()
     */
    public static function build(CatalogProduct $product, int $maxTextChars, string $promptHash, array $boilerplate, array $known): self
    {
        $text = (new TextCondenser)->condense(ProductDigest::sections($product), $maxTextChars, $boilerplate, headingsWithContent: true)['text'];

        $request = array_filter([
            'id' => $product->external_id,
            'title' => $product->title,
            'text' => $text,
            'known' => $known,
        ], fn ($value): bool => $value !== null && $value !== '' && $value !== []);

        return new self(
            $request,
            // Only the text decides whether an answer still fits: a fact approved later changes `known`, not the product.
            ['product_id' => $product->id, 'text' => $text, 'text_hash' => hash('sha256', $text)],
            hash('sha256', $promptHash.'|'.json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        );
    }

    /**
     * Approved facts and what code read (the price note, what the shopper chooses) as short lines,
     * in a fixed order: everything the product page and the widget already show.
     *
     * @param  Collection<int, EnrichmentFact>  $facts
     * @param  array<string, mixed>  $reading  the product's code reading
     * @return list<string>
     */
    public static function known(Collection $facts, VocabularyDefinition $vocabulary, array $reading = [], string $locale = 'he'): array
    {
        $lines = [];

        if (isset($reading['price_unit'])) {
            $lines[] = (string) $reading['price_unit'];
        }

        foreach ((array) ($reading['choices'] ?? []) as $choice) {
            $lines[] = __('enrichment::enrichment.known_choice', ['name' => $choice['name'] ?? ''], $locale);
        }

        foreach ($facts as $fact) {
            $line = match ($fact->kind) {
                FactKind::Type => $vocabulary->label('type', (string) $fact->value_text, $locale),
                FactKind::Choice => $vocabulary->label('attribute', $fact->key, $locale).': '.$vocabulary->label('attribute', $fact->key, $locale, (string) $fact->value_text),
                FactKind::Spec => $vocabulary->label('attribute', $fact->key, $locale).': '.Measurement::compact((float) $fact->value_number).' '.$fact->unit,
                FactKind::Flag => $vocabulary->label('attribute', $fact->key, $locale),
                FactKind::Tag => $vocabulary->label('tag', (string) $fact->value_text, $locale),
                FactKind::Use => $vocabulary->label('use', (string) $fact->value_text, $locale),
                FactKind::Brand => (string) $fact->value_text,
                default => null,
            };

            if ($line !== null && $line !== '') {
                $lines[] = $line;
            }
        }

        $lines = array_values(array_unique($lines));
        sort($lines);

        return $lines;
    }
}
