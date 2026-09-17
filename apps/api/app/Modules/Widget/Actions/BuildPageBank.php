<?php

namespace App\Modules\Widget\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentContentProduct;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentRanking;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use Illuminate\Support\Collection;

/**
 * Everything the storefront widget shows on one page, built from checked facts only. No model
 * runs here: sentences are templates filled with approved values.
 *
 *   product page   position (superlatives), specs, complement (the merchant's cross-sells),
 *                  guides (articles this product was matched to)
 *   article        article_products (the products matched to the article)
 *
 * Prices and stock in the result are from the last catalog sync. The widget replaces them
 * with live values from the store before showing anything.
 */
final class BuildPageBank
{
    public const TYPES = ['product', 'content'];

    public const LOCALES = ['he', 'en'];

    /** Section candidate id => display model in the event spec. */
    public const MODELS = [
        'position' => 'position',
        'specs' => 'specs',
        'complement' => 'complement',
        'guides' => 'guide_card',
        'article_products' => 'article_products',
    ];

    private const MAX_POSITIONS = 3;

    private const MAX_SPECS = 8;

    private const MAX_GUIDES = 2;

    /** @var array<string, mixed> vocabulary definitions by key, per build */
    private array $vocabularies = [];

    private string $locale = 'he';

    public function __construct(private readonly TenantContext $tenant) {}

    /** @return array<string, mixed> */
    public function handle(string $shopId, string $type, string $externalId, string $locale): array
    {
        $this->locale = in_array($locale, self::LOCALES, true) ? $locale : 'he';
        $this->vocabularies = [];

        $bank = [
            'v' => 1,
            'shop' => $shopId,
            'locale' => $this->locale,
            'dir' => $this->locale === 'he' ? 'rtl' : 'ltr',
            'page' => ['type' => $type, 'id' => $externalId],
            'enabled' => false,
            'placement' => [
                'selector' => (string) Settings::get("widget.{$type}_selector", $shopId),
                'position' => (string) Settings::get("widget.{$type}_position", $shopId),
                'floating' => (bool) Settings::get('widget.floating_fallback', $shopId),
            ],
            'bank_version' => 1,
            'teaser' => null,
            'sections' => [],
            'labels' => $this->labels(),
        ];

        if (! Features::enabled($type === 'product' ? 'widget.on_products' : 'widget.on_content', $shopId)) {
            return $bank;
        }

        $maxProducts = (int) Settings::get('widget.max_products', $shopId);

        [$sections, $version] = $this->tenant->run($shopId, fn (): array => $type === 'product'
            ? $this->productSections($externalId, $maxProducts)
            : $this->contentSections($externalId, $maxProducts));

        $bank['enabled'] = true;
        $bank['sections'] = $sections;
        $bank['bank_version'] = max(1, $version);
        $bank['teaser'] = $sections === [] ? null : $this->teaser($sections[0]);

        return $bank;
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    private function productSections(string $externalId, int $maxProducts): array
    {
        $product = CatalogProduct::query()->active()->where('external_id', $externalId)->first();

        if ($product === null) {
            return [[], 1];
        }

        $sections = [];
        $version = (int) $product->synced_at?->timestamp;

        $rankings = EnrichmentRanking::query()
            ->where('product_id', $product->id)
            ->where('rank', 1)
            ->orderBy('tied')
            ->orderByDesc('set_size')
            ->orderBy('metric')
            ->limit(self::MAX_POSITIONS)
            ->get();

        if ($rankings->isNotEmpty()) {
            $version = max($version, (int) $rankings->max(fn (EnrichmentRanking $r): int => (int) $r->computed_at->timestamp));
            $sections[] = $this->section('position', [
                'lines' => $rankings->map(fn (EnrichmentRanking $r): array => array_filter([
                    'text' => $this->positionText($r),
                    'metric' => $r->metric,
                    // The widget hides a price superlative when the live price is not this one.
                    'price' => $r->metric === 'price' ? (float) $r->value : null,
                ], fn ($v): bool => $v !== null))->values()->all(),
            ]);
        }

        $specs = $this->specs($product->id);
        if ($specs !== []) {
            $sections[] = $this->section('specs', ['specs' => $specs]);
        }

        $crossSells = array_values(array_unique(array_column(array_filter(
            $product->merchantRelations(),
            fn (array $r): bool => $r['type'] === 'cross_sell',
        ), 'target')));

        if ($crossSells !== []) {
            $cards = $this->cards(
                CatalogProduct::query()->active()->where('in_stock', true)->where('purchasable', true)
                    ->whereIn('external_id', $crossSells)->get()
                    ->sortBy(fn (CatalogProduct $p): int => (int) array_search($p->external_id, $crossSells, true))
                    ->take($maxProducts),
            );

            if ($cards !== []) {
                $sections[] = $this->section('complement', ['products' => $cards]);
            }
        }

        $guides = EnrichmentContentProduct::query()
            ->with('content')
            ->where('product_id', $product->id)
            ->whereHas('content', fn ($q) => $q->whereNull('removed_at'))
            ->orderByDesc('score')
            ->orderBy('rank')
            ->limit(self::MAX_GUIDES)
            ->get();

        if ($guides->isNotEmpty()) {
            $version = max($version, (int) $guides->max(fn (EnrichmentContentProduct $g): int => (int) $g->computed_at->timestamp));
            $sections[] = $this->section('guides', [
                'guides' => $guides->map(fn (EnrichmentContentProduct $g): array => [
                    'id' => $g->content->external_id,
                    'title' => $g->content->title,
                    'url' => $g->content->url,
                    'image' => $g->content->image_url,
                ])->values()->all(),
            ]);
        }

        return [$sections, $version];
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    private function contentSections(string $externalId, int $maxProducts): array
    {
        $content = CatalogContent::query()->active()->where('external_id', $externalId)->orderBy('type')->first();

        if ($content === null) {
            return [[], 1];
        }

        $matches = EnrichmentContentProduct::query()
            ->with('product')
            ->where('content_id', $content->id)
            ->whereHas('product', fn ($q) => $q->whereNull('removed_at')->where('in_stock', true)->where('purchasable', true))
            ->orderBy('rank')
            ->limit($maxProducts)
            ->get();

        if ($matches->isEmpty()) {
            return [[], 1];
        }

        $products = $matches->map(fn (EnrichmentContentProduct $m): CatalogProduct => $m->product);
        $reasons = EnrichmentRanking::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->where('rank', 1)
            ->where('tied', false)
            ->orderByDesc('set_size')
            ->get()
            ->unique('product_id')
            ->mapWithKeys(fn (EnrichmentRanking $r): array => [$r->product_id => $this->positionText($r)]);

        $version = (int) $matches->max(fn (EnrichmentContentProduct $m): int => (int) $m->computed_at->timestamp);

        return [[$this->section('article_products', ['products' => $this->cards($products, $reasons)])], $version];
    }

    /**
     * @param  Collection<int, CatalogProduct>  $products
     * @param  Collection<string, string>|null  $reasons  product id => sentence
     * @return list<array<string, mixed>>
     */
    private function cards(Collection $products, ?Collection $reasons = null): array
    {
        return $products->map(fn (CatalogProduct $p): array => array_filter([
            'id' => $p->external_id,
            'title' => $p->title,
            'url' => $p->url,
            'image' => $p->image_url,
            'price' => $p->price === null ? null : (float) $p->price,
            'currency' => $p->currency,
            'type' => $p->type,
            'reason' => $reasons?->get($p->id),
        ], fn ($v): bool => $v !== null))->values()->all();
    }

    /** @return list<array{label: string, value: string}> */
    private function specs(string $productId): array
    {
        $facts = EnrichmentFact::query()
            ->with('vocabulary')
            ->where('product_id', $productId)
            ->where('status', FactStatus::Approved)
            ->whereIn('kind', [FactKind::Type, FactKind::Spec, FactKind::Choice, FactKind::Flag])
            ->orderBy('created_at')
            ->get()
            ->keyBy(fn (EnrichmentFact $f): string => $f->kind->value.'|'.$f->key);

        if ($facts->isEmpty()) {
            return [];
        }

        $definition = $facts->first()->vocabulary?->definition();

        if ($definition === null) {
            return [];
        }

        $rows = [];

        if (($type = $facts->first(fn (EnrichmentFact $f): bool => $f->kind === FactKind::Type)) !== null) {
            $rows[] = ['label' => __('widget::bank.type', [], $this->locale), 'value' => $definition->label('type', (string) $type->value_text, $this->locale)];
        }

        // What it is first (choices), then numbers, then yes/no features; each in vocabulary order.
        $order = [FactKind::Choice->value => 0, FactKind::Spec->value => 1, FactKind::Flag->value => 2];
        $attributes = $definition->attributes();
        usort($attributes, function (array $a, array $b) use ($facts, $order): int {
            $kind = fn (array $attribute): int => $order[$facts->first(fn (EnrichmentFact $f): bool => $f->key === $attribute['key'])?->kind->value] ?? 3;

            return $kind($a) <=> $kind($b);
        });

        foreach ($attributes as $attribute) {
            $fact = $facts->first(fn (EnrichmentFact $f): bool => $f->key === $attribute['key'] && $f->kind !== FactKind::Type);

            if ($fact === null) {
                continue;
            }

            $value = match ($fact->kind) {
                FactKind::Spec => $this->measure((float) $fact->value_number, $fact->unit),
                FactKind::Choice => $definition->label('attribute', $fact->key, $this->locale, (string) $fact->value_text),
                default => __('widget::bank.yes', [], $this->locale),
            };

            $rows[] = ['label' => $definition->label('attribute', $fact->key, $this->locale), 'value' => $value];
        }

        return array_slice($rows, 0, self::MAX_SPECS);
    }

    private function positionText(EnrichmentRanking $ranking): string
    {
        $vocabularyKey = explode('|', $ranking->set_key)[0];
        $definition = $this->vocabularies[$vocabularyKey] ??= EnrichmentVocabulary::query()
            ->where('key', $vocabularyKey)->where('active', true)->first()?->definition();

        $metric = $ranking->metric === 'price'
            ? __('widget::bank.position.price', [], $this->locale)
            : ($definition?->label('attribute', $ranking->metric, $this->locale) ?? $ranking->metric);

        $set = [];
        foreach ($ranking->set_facets as $key => $value) {
            $set[] = $key === 'type'
                ? ($definition?->label('type', (string) $value, $this->locale) ?? $value)
                : ($definition?->label('attribute', (string) $key, $this->locale, (string) $value) ?? $value);
        }

        $template = ($ranking->tied ? 'tied_' : 'best_').($ranking->direction === 'min' ? 'min' : 'max');
        $text = __("widget::bank.position.{$template}", [
            'metric' => $metric,
            'size' => $ranking->set_size,
            'set' => implode(' · ', $set),
        ], $this->locale);

        return $ranking->metric === 'price' ? $text : $text.' · '.$this->measure((float) $ranking->value, $ranking->unit);
    }

    private function measure(float $value, ?string $unit): string
    {
        if ($unit === 'mm' && $value >= 1000) {
            [$value, $unit] = [$value / 1000, 'm'];
        }

        $number = rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');

        if ($unit === null || $unit === '') {
            return $number;
        }

        $label = __("widget::bank.units.{$unit}", [], $this->locale);

        return $number.' '.(str_starts_with($label, 'widget::') ? $unit : $label);
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function section(string $candidate, array $content): array
    {
        return [
            'candidate' => $candidate,
            'model' => self::MODELS[$candidate],
            'title' => __('widget::bank.titles.'.self::MODELS[$candidate], [], $this->locale),
        ] + $content;
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array{candidate: string, model: string, text: string}
     */
    private function teaser(array $section): array
    {
        $text = match ($section['candidate']) {
            'position' => $section['lines'][0]['text'],
            'article_products' => trans_choice('widget::bank.teasers.article_products', count($section['products']), ['count' => count($section['products'])], $this->locale),
            default => __('widget::bank.teasers.'.$section['model'], [], $this->locale),
        };

        return ['candidate' => $section['candidate'], 'model' => $section['model'], 'text' => $text];
    }

    /** @return array<string, string> */
    private function labels(): array
    {
        return (array) __('widget::bank.ui', [], $this->locale);
    }
}
