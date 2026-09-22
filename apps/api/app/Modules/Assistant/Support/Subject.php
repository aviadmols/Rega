<?php

namespace App\Modules\Assistant\Support;

use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentContentProduct;
use App\Modules\Enrichment\Models\EnrichmentFact;

/**
 * The page a shopper is asking about: a product, or a guide the store published.
 *
 * Both are answered the same way — from what the store itself says, checked before anyone sees
 * it — but they are not the same subject. A product is its checked facts and its description; an
 * article is its own text and the products it points to. Each carries the prompts written for it,
 * and the column its answers are saved under, so a question asked on a guide is never mixed with
 * a question asked on a product.
 */
final class Subject
{
    public const PRODUCT = 'product';

    public const ARTICLE = 'article';

    /**
     * @param  array<string, mixed>  $context  everything the writer may answer from
     * @param  array<string, mixed>  $brief  the little the scope check needs
     */
    private function __construct(
        public readonly string $kind,
        public readonly string $id,
        public readonly string $shopId,
        public readonly string $externalId,
        public readonly string $title,
        public readonly array $context,
        public readonly array $brief,
    ) {}

    public static function product(CatalogProduct $product, int $maxTextChars): self
    {
        $facts = EnrichmentFact::query()
            ->with('vocabulary')
            ->where('product_id', $product->id)
            ->where('status', FactStatus::Approved)
            ->orderBy('kind')->orderBy('key')
            ->get();

        $lines = [];
        $highlights = [];

        foreach ($facts as $fact) {
            if ($fact->kind === FactKind::Highlight) {
                $highlights[] = $fact->key.': '.$fact->value_text;

                continue;
            }

            $definition = $fact->vocabulary?->definition();
            $lines[] = match ($fact->kind) {
                FactKind::Type => $definition?->label('type', (string) $fact->value_text, 'he') ?? (string) $fact->value_text,
                FactKind::Choice => ($definition?->label('attribute', $fact->key, 'he') ?? $fact->key).': '.($definition?->label('attribute', $fact->key, 'he', (string) $fact->value_text) ?? $fact->value_text),
                FactKind::Spec => ($definition?->label('attribute', $fact->key, 'he') ?? $fact->key).': '.rtrim(rtrim(number_format((float) $fact->value_number, 3, '.', ''), '0'), '.').' '.$fact->unit,
                FactKind::Use => ($definition?->label('use', (string) $fact->value_text, 'he') ?? (string) $fact->value_text),
                default => $fact->key.': '.$fact->value_text,
            };
        }

        $category = implode(' > ', $product->categoryPaths()[0] ?? []);
        $text = trim(implode("\n", array_filter([$product->shortDescription(), $product->description()])));

        return new self(
            self::PRODUCT,
            $product->id,
            $product->shop_id,
            $product->external_id,
            $product->title,
            [
                'title' => $product->title,
                'category' => $category,
                'facts' => array_values(array_unique($lines)),
                'highlights' => $highlights,
                'text' => mb_substr($text, 0, $maxTextChars),
            ],
            ['title' => $product->title, 'category' => $category],
        );
    }

    public static function article(CatalogContent $article, int $maxTextChars): self
    {
        // The products the guide points to, by name only: enough to answer "which one do you
        // mean", never enough to invent a spec.
        $products = EnrichmentContentProduct::query()
            ->with('product')
            ->where('content_id', $article->id)
            ->whereHas('product', fn ($q) => $q->whereNull('removed_at'))
            ->orderBy('rank')
            ->get()
            ->map(fn (EnrichmentContentProduct $match): string => (string) $match->product?->title)
            ->filter()
            ->values()
            ->all();

        $text = trim((string) ($article->body ?? $article->excerpt ?? ''));

        return new self(
            self::ARTICLE,
            $article->id,
            $article->shop_id,
            $article->external_id,
            $article->title,
            [
                'title' => $article->title,
                'text' => mb_substr($text, 0, $maxTextChars),
                'products' => $products,
            ],
            ['title' => $article->title],
        );
    }

    /** The column an answer about this subject is saved under. */
    public function column(): string
    {
        return $this->kind === self::ARTICLE ? 'content_id' : 'product_id';
    }

    /** The prompt written for this subject: "answer" for a product, "answer_article" for a guide. */
    public function prompt(string $name): string
    {
        return $this->kind === self::ARTICLE ? $name.'_article' : $name;
    }
}
