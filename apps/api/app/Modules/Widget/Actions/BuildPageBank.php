<?php

namespace App\Modules\Widget\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsScore;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\RelationKind;
use App\Modules\Enrichment\Models\EnrichmentCodeReading;
use App\Modules\Enrichment\Models\EnrichmentContentProduct;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentProductRelation;
use App\Modules\Enrichment\Models\EnrichmentRanking;
use App\Modules\Enrichment\Models\EnrichmentRelationRules;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Widget\Support\GuideRelevance;
use Illuminate\Support\Collection;

/**
 * Everything the storefront widget shows on one page, built from checked facts only. No model
 * runs here: sentences are templates filled with approved values. Each section is one circle in
 * the widget.
 *
 *   product page   position (superlatives and level), specs, complement (relations: merchant
 *                  links, matching battery, care products...), family (other sizes),
 *                  alternatives, on_sale (alternatives on sale), good_for (jobs and guides)
 *   article        article_products (the products matched to the article)
 *
 * A product page also carries `compare`: its specs keyed for comparing with a product of the
 * same type the shopper viewed before, which the widget keeps in the browser.
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
        'highlights' => 'explainer',
        'specs' => 'specs',
        'complement' => 'complement',
        'family' => 'family',
        'alternatives' => 'alternative',
        'on_sale' => 'on_sale',
        'good_for' => 'good_for',
        'guides' => 'guide_card',
        'article_products' => 'article_products',
    ];

    private const MAX_POSITIONS = 3;

    private const MAX_SPECS = 10;

    private const MAX_GUIDES = 3;

    private const MAX_FAMILY = 8;

    private const SPARE_PRODUCTS = 4;

    private const MAX_BROWSE_LINKS = 3;

    private const MAX_HIGHLIGHTS = 4;

    private const MIN_LEVEL_SET = 3;

    /** A product in the best quarter of its set gets "among the highest". */
    private const LEVEL_SHARE = 0.25;

    /** @var array<string, mixed> vocabulary definitions by key, per build */
    private array $vocabularies = [];

    /** @var Collection<string, CatalogCategory>|null */
    private ?Collection $categories = null;

    private string $locale = 'he';

    private string $shopId = '';

    public function __construct(private readonly TenantContext $tenant) {}

    /** @return array<string, mixed> */
    public function handle(string $shopId, string $type, string $externalId, string $locale): array
    {
        $this->locale = in_array($locale, self::LOCALES, true) ? $locale : 'he';
        $this->shopId = $shopId;
        $this->vocabularies = [];
        $this->categories = null;

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
            'compare' => null,
            'labels' => $this->labels(),
        ];

        if (! Features::enabled($type === 'product' ? 'widget.on_products' : 'widget.on_content', $shopId)) {
            return $bank;
        }

        $maxProducts = (int) Settings::get('widget.max_products', $shopId);
        // Sections carry spares, so a product dropped for never being clicked has a replacement.
        $pool = $maxProducts + self::SPARE_PRODUCTS;

        [$sections, $version, $compare] = $this->tenant->run($shopId, fn (): array => $type === 'product'
            ? $this->productSections($externalId, $pool)
            : [...$this->contentSections($externalId, $pool), null]);

        $bank['enabled'] = true;
        $bank['sections'] = $this->tenant->run($shopId, fn (): array => $this->learned($shopId, $type, $externalId, $sections, $maxProducts));
        $bank['bank_version'] = max(1, $version);
        $bank['teaser'] = $bank['sections'] === [] ? null : $this->teaser($bank['sections'][0]);
        $bank['compare'] = $compare;
        // The question box (Assistant module) on product pages, when the shop has it on.
        $bank['ask'] = $type === 'product' && Features::enabled('assistant.on_products', $shopId);

        return $bank;
    }

    /** @return array{0: list<array<string, mixed>>, 1: int, 2: array<string, mixed>|null} */
    private function productSections(string $externalId, int $maxProducts): array
    {
        $product = CatalogProduct::query()->active()->where('external_id', $externalId)->first();

        if ($product === null) {
            return [[], 1, null];
        }

        $sections = [];
        $version = (int) $product->synced_at?->timestamp;

        $facts = EnrichmentFact::query()
            ->with('vocabulary')
            ->where('product_id', $product->id)
            ->where('status', FactStatus::Approved)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $definition = $facts->first(fn (EnrichmentFact $f): bool => $f->vocabulary !== null)?->vocabulary?->definition();
        $reading = EnrichmentCodeReading::query()->where('product_id', $product->id)->value('reading');

        // Why this model: superlatives, then "among the highest" levels, then a professional tag.
        $rankings = EnrichmentRanking::query()
            ->where('product_id', $product->id)
            ->where('rank', 1)
            ->orderBy('tied')
            ->orderByDesc('set_size')
            ->orderBy('metric')
            ->limit(self::MAX_POSITIONS)
            ->get();

        $lines = $rankings->map(fn (EnrichmentRanking $r): array => array_filter([
            'text' => $this->positionText($r),
            'metric' => $r->metric,
            // The widget hides a price superlative when the live price is not this one.
            'price' => $r->metric === 'price' ? (float) $r->value : null,
        ], fn ($v): bool => $v !== null))->values()->all();

        if ($definition !== null) {
            $lines = [...$lines, ...$this->levelLines($product, $facts, $definition, $rankings->pluck('metric')->all())];

            if ($facts->contains(fn (EnrichmentFact $f): bool => $f->kind === FactKind::Tag && $f->value_text === 'for_professionals')) {
                $lines[] = ['text' => __('widget::bank.position.professional', [], $this->locale), 'metric' => 'tag'];
            }
        }

        if ($lines !== []) {
            $version = max($version, (int) $rankings->max(fn (EnrichmentRanking $r): int => (int) $r->computed_at->timestamp));
            $sections[] = $this->section('position', ['lines' => array_slice($lines, 0, self::MAX_POSITIONS + 2)]);
        }

        // What a shopper should know, from the product's own text, in the order the writer chose.
        $highlights = $this->highlights($facts->where('kind', FactKind::Highlight)->sortBy('value_number')->take(self::MAX_HIGHLIGHTS)->values());
        if ($highlights !== []) {
            $sections[] = $this->section('highlights', ['items' => $highlights]);
        }

        $specs = $this->specs($facts, $definition, (array) ($reading ?? []));
        if ($specs !== []) {
            $sections[] = $this->section('specs', ['specs' => $specs]);
        }

        // Products shown with this one, as ComputeProductRelations found them.
        $relations = EnrichmentProductRelation::query()
            ->with('related')
            ->where('product_id', $product->id)
            ->whereHas('related', fn ($q) => $q->whereNull('removed_at')->where('in_stock', true)->where('purchasable', true))
            ->orderByDesc('score')
            ->get()
            ->groupBy(fn (EnrichmentProductRelation $r): string => $r->kind->value);

        $version = max($version, (int) collect($relations->flatten())->max(fn (EnrichmentProductRelation $r): int => (int) $r->computed_at->timestamp));
        $ruleLabels = $this->ruleLabels();

        // Another size of this product is shown under other sizes, not again as a complement.
        $sizes = $relations->get(RelationKind::Family->value, collect())->pluck('related_product_id')->all();
        $complementKinds = self::kinds($relations->get(RelationKind::Complement->value, collect())
            ->reject(fn (EnrichmentProductRelation $r): bool => in_array($r->related_product_id, $sizes, true)));
        $complements = self::varied($complementKinds, $maxProducts);
        if ($complements->isNotEmpty()) {
            $sections[] = $this->section('complement', array_filter([
                'products' => $this->cards(
                    $complements->map(fn (EnrichmentProductRelation $r): CatalogProduct => $r->related),
                    $complements->mapWithKeys(fn (EnrichmentProductRelation $r): array => [$r->related_product_id => $this->relationReason($r, $ruleLabels)]),
                ),
                'categories' => $this->browseLinks(array_map(fn (array $kind): array => array_map(fn (EnrichmentProductRelation $r): CatalogProduct => $r->related, $kind), $complementKinds)),
            ]));
        } elseif (($crossSells = $this->merchantCrossSells($product, $maxProducts))->isNotEmpty()) {
            // Relations not computed yet: the merchant's own cross-sells.
            $sections[] = $this->section('complement', ['products' => $this->cards($crossSells)]);
        }

        $family = $relations->get(RelationKind::Family->value, collect())->take(self::MAX_FAMILY);
        if ($family->isNotEmpty()) {
            $sections[] = $this->section('family', ['products' => $this->cards($family->map(fn (EnrichmentProductRelation $r): CatalogProduct => $r->related))]);
        }

        $alternatives = $relations->get(RelationKind::Alternative->value, collect());
        if ($alternatives->isNotEmpty()) {
            $sections[] = $this->section('alternatives', array_filter([
                'products' => $this->cards($alternatives->take($maxProducts)->map(fn (EnrichmentProductRelation $r): CatalogProduct => $r->related)),
                'categories' => $this->browseLinks([$alternatives->map(fn (EnrichmentProductRelation $r): CatalogProduct => $r->related)->all()]),
            ]));

            // The widget checks "on sale" again with live prices.
            $onSale = $alternatives->filter(fn (EnrichmentProductRelation $r): bool => $r->related->on_sale)->take($maxProducts);
            if ($onSale->isNotEmpty()) {
                $sections[] = $this->section('on_sale', ['products' => $this->cards($onSale->map(fn (EnrichmentProductRelation $r): CatalogProduct => $r->related)), 'require_sale' => true]);
            }
        }

        // Good for: approved jobs, with guides matched to the product or to those jobs.
        $uses = $facts->where('kind', FactKind::Use)->pluck('value_text')->unique()->values()->all();
        $guides = $this->guides($product, $uses);

        if ($uses !== []) {
            $labels = array_map(fn (string $use): string => $definition?->label('use', $use, $this->locale) ?? $use, $uses);
            $sections[] = $this->section('good_for', ['uses' => $labels, 'guides' => $guides], ['uses' => implode(' · ', array_slice($labels, 0, 2))]);
        } elseif ($guides !== []) {
            $sections[] = $this->section('guides', ['guides' => $guides]);
        }

        return [$sections, $version, $this->compare($facts, $definition, $specs)];
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
     * "Among the highest power of 12 cordless angle grinders": the product is in the best quarter
     * of products of its type and set, for a spec the vocabulary ranks. Skipped where a superlative
     * already says more.
     *
     * @param  Collection<int, EnrichmentFact>  $facts
     * @param  list<string>  $alreadyRanked
     * @return list<array{text: string, metric: string}>
     */
    private function levelLines(CatalogProduct $product, Collection $facts, mixed $definition, array $alreadyRanked): array
    {
        $type = $facts->first(fn (EnrichmentFact $f): bool => $f->kind === FactKind::Type)?->value_text;

        if ($type === null) {
            return [];
        }

        $facets = ['type' => $type];
        foreach ($definition->setBy() as $key) {
            $choice = $facts->first(fn (EnrichmentFact $f): bool => $f->kind === FactKind::Choice && $f->key === $key)?->value_text;

            if ($choice === null) {
                return [];
            }
            $facets[$key] = $choice;
        }

        // Products of the same type and set, in stock.
        $peers = EnrichmentFact::query()
            ->where('status', FactStatus::Approved)->where('kind', FactKind::Type)->where('value_text', $type)
            ->whereHas('product', fn ($q) => $q->whereNull('removed_at')->where('in_stock', true))
            ->pluck('product_id')->unique();

        foreach (array_slice($facets, 1, null, true) as $key => $value) {
            $peers = $peers->intersect(EnrichmentFact::query()->where('status', FactStatus::Approved)->where('kind', FactKind::Choice)
                ->where('key', $key)->where('value_text', $value)->pluck('product_id'));
        }

        if ($peers->count() < self::MIN_LEVEL_SET) {
            return [];
        }

        $set = [];
        foreach ($facets as $key => $value) {
            $set[] = $key === 'type' ? $definition->label('type', $value, $this->locale) : $definition->label('attribute', $key, $this->locale, $value);
        }

        $lines = [];

        foreach ($definition->attributes() as $attribute) {
            $direction = $attribute['rank'] ?? null;
            $own = $facts->first(fn (EnrichmentFact $f): bool => $f->kind === FactKind::Spec && $f->key === $attribute['key']);

            if ($direction === null || $own === null || in_array($attribute['key'], $alreadyRanked, true)) {
                continue;
            }

            $values = EnrichmentFact::query()->where('status', FactStatus::Approved)->where('kind', FactKind::Spec)
                ->where('key', $attribute['key'])->whereIn('product_id', $peers->all())
                ->get(['product_id', 'value_number'])->unique('product_id')->pluck('value_number')->map(fn ($v): float => (float) $v);

            if ($values->count() < self::MIN_LEVEL_SET) {
                continue;
            }

            $mine = (float) $own->value_number;
            $better = $values->filter(fn (float $v): bool => $direction === 'max' ? $v > $mine : $v < $mine)->count();

            if ($better / $values->count() > self::LEVEL_SHARE) {
                continue;
            }

            $lines[] = [
                'text' => __('widget::bank.position.level_'.$direction, [
                    'metric' => $definition->label('attribute', $attribute['key'], $this->locale),
                    'size' => $values->count(),
                    'set' => implode(' · ', $set),
                ], $this->locale).' · '.$this->measure($mine, $own->unit),
                'metric' => $attribute['key'],
            ];
        }

        return $lines;
    }

    /**
     * What worked, applied. Scores come from the Analytics module's nightly run.
     *
     * - Products inside a section: the ones clicked, added or bought from it on this page first.
     *   Once the section was opened enough times here, the products it showed and nobody clicked
     *   are dropped and the spares behind them move up. A section left with nothing is dropped.
     *   Family (other sizes) is reordered, never dropped.
     * - Sections: in order of their score on this page, or across the shop while the page has none.
     *   A section nobody has seen yet gets the best known score, so it gets seen.
     *
     * Without scores the built order stands.
     *
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function learned(string $shopId, string $type, string $externalId, array $sections, int $maxProducts): array
    {
        $scores = $sections === [] ? collect() : AnalyticsScore::query()
            ->where(fn ($q) => $q->where('scope', AnalyticsScore::SCOPE_MODULE)
                ->orWhere(fn ($q) => $q->where('page_type', $type)->where('page_external_id', $externalId)))
            ->get();

        $module = $scores->where('scope', AnalyticsScore::SCOPE_MODULE)->keyBy('candidate');
        $page = $scores->where('scope', AnalyticsScore::SCOPE_PAGE)->keyBy('candidate');
        $related = $scores->where('scope', AnalyticsScore::SCOPE_RELATED)->groupBy('candidate')
            ->map(fn (Collection $rows): array => $rows->mapWithKeys(fn (AnalyticsScore $s): array => [$s->related_external_id => (float) $s->score])->all());
        $dropAfter = (int) Settings::get('analytics.drop_related_after_opens', $shopId);

        foreach ($sections as $i => $section) {
            if (! isset($section['products'])) {
                continue;
            }

            $candidate = (string) $section['candidate'];
            $worked = $related->get($candidate, []);
            $products = $section['products'];

            if ($candidate !== 'family' && (int) ($page->get($candidate)?->opens ?? 0) >= $dropAfter) {
                $shown = array_column(array_slice($products, 0, $maxProducts), 'id');
                $products = array_filter($products, fn (array $p): bool => isset($worked[(string) $p['id']]) || ! in_array($p['id'], $shown, true));
            }

            $products = self::workedFirst($products, $worked);
            $sections[$i]['products'] = $candidate === 'family' ? $products : array_slice($products, 0, $maxProducts);
        }

        // Guides: the ones read from this section first.
        foreach ($sections as $i => $section) {
            if (! empty($section['guides'])) {
                $sections[$i]['guides'] = self::workedFirst($section['guides'], $related->get((string) $section['candidate'], []));
            }
        }

        $sections = array_values(array_filter($sections, fn (array $s): bool => ! isset($s['products']) || $s['products'] !== []));

        if ($scores->isEmpty()) {
            return $sections;
        }

        $known = $module->pluck('score')->map(fn ($s): float => (float) $s)->filter(fn (float $s): bool => $s > 0);
        $explore = $known->isEmpty() ? 0.0 : (float) $known->max();

        $rank = fn (array $section): float => (float) ($page->get($section['candidate'])?->score
            ?? $module->get($section['candidate'])?->score
            ?? $explore);

        $order = array_keys($sections);
        usort($order, fn (int $a, int $b): int => [$rank($sections[$b]), $a] <=> [$rank($sections[$a]), $b]);

        return array_map(fn (int $i): array => $sections[$i], $order);
    }

    /**
     * Highlights for the widget. One whose quote the store repeats on many products ("a deviation
     * of 2-3 mm is possible") says little about this product: it goes last and is marked common,
     * so the widget does not make it the key sentence.
     *
     * @param  Collection<int, EnrichmentFact>  $facts  in the writer's order
     * @return list<array{key: string, text: string, common?: bool}>
     */
    private function highlights(Collection $facts): array
    {
        if ($facts->isEmpty()) {
            return [];
        }

        $threshold = (int) Settings::get('widget.common_highlight_products', $this->shopId);
        $shared = EnrichmentFact::query()
            ->where('kind', FactKind::Highlight)
            ->where('status', FactStatus::Approved)
            ->whereIn('quote', $facts->pluck('quote')->filter()->all())
            ->selectRaw('quote, count(distinct product_id) as products')
            ->groupBy('quote')
            ->pluck('products', 'quote');

        $items = $facts->map(fn (EnrichmentFact $f): array => array_filter([
            'key' => $f->key,
            'text' => (string) $f->value_text,
            'common' => (int) ($shared[$f->quote] ?? 0) >= $threshold ?: null,
        ], fn ($v): bool => $v !== null))->all();

        usort($items, fn (array $a, array $b): int => isset($a['common']) <=> isset($b['common']));

        return $items;
    }

    /**
     * Items by their related score, highest first; items without one keep their built order after.
     *
     * @param  array<int, array<string, mixed>>  $items  each with an id
     * @param  array<string, float>  $worked  item id => related score
     * @return list<array<string, mixed>>
     */
    private static function workedFirst(array $items, array $worked): array
    {
        $items = array_values($items);
        $positions = array_keys($items);
        usort($positions, fn (int $a, int $b): int => [$worked[(string) $items[$b]['id']] ?? 0.0, $a] <=> [$worked[(string) $items[$a]['id']] ?? 0.0, $b]);

        return array_map(fn (int $p): array => $items[$p], $positions);
    }

    /**
     * @param  Collection<int, CatalogProduct>  $products
     * @param  Collection<string, string>|null  $reasons  product id => sentence
     * @return list<array<string, mixed>>
     */
    private function cards(Collection $products, ?Collection $reasons = null): array
    {
        return $products->values()->map(fn (CatalogProduct $p): array => array_filter([
            'id' => $p->external_id,
            'title' => $p->title,
            'url' => $p->url,
            'image' => $p->image_url,
            'price' => $p->price === null ? null : (float) $p->price,
            'currency' => $p->currency,
            'type' => $p->type,
            // Shown next to the price, e.g. "מחיר למטר": without it a price per meter reads as the item price.
            'price_note' => self::priceNote($p),
            'needs_options' => self::needsOptions($p) ?: null,
            'reason' => $reasons?->get($p->id),
        ], fn ($v): bool => $v !== null && $v !== ''))->all();
    }

    /** @return Collection<int, CatalogProduct> */
    private function merchantCrossSells(CatalogProduct $product, int $limit): Collection
    {
        $targets = array_values(array_unique(array_column(array_filter($product->merchantRelations(), fn (array $r): bool => $r['type'] === 'cross_sell'), 'target')));

        if ($targets === []) {
            return collect();
        }

        return CatalogProduct::query()->active()->where('in_stock', true)->where('purchasable', true)
            ->whereIn('external_id', $targets)->get()
            ->sortBy(fn (CatalogProduct $p): int => (int) array_search($p->external_id, $targets, true))
            ->take($limit)
            ->values();
    }

    /** @param array<string, array<string, string>> $ruleLabels */
    private function relationReason(EnrichmentProductRelation $relation, array $ruleLabels): ?string
    {
        $reasons = $relation->reasons;
        // A merchant link a rule also found says what the rule says.
        $label = collect([$relation->source, ...($reasons['also'] ?? [])])
            ->map(fn (string $source): ?string => $ruleLabels[$source][$this->locale] ?? null)
            ->first(fn (?string $label): bool => $label !== null);

        if ($label === null) {
            return null;
        }

        $details = array_filter([
            $reasons['brand'] ?? null,
            isset($reasons['voltage_v']) ? $this->measure((float) $reasons['voltage_v'], 'V') : null,
        ]);

        return implode(' · ', [$label, ...$details]);
    }

    /**
     * Related products by kind: the rule that found the product; a merchant link no rule found takes
     * the rule of a product in its deepest store category, or that category. Kinds come in the
     * order of their best score.
     *
     * @param  Collection<int, EnrichmentProductRelation>  $relations  best score first
     * @return list<list<EnrichmentProductRelation>>
     */
    private static function kinds(Collection $relations): array
    {
        $category = fn (EnrichmentProductRelation $r): string => (string) (collect((array) ($r->related->payload['categories'] ?? []))
            ->sortByDesc(fn ($c): int => count((array) ($c['path'] ?? [])))
            ->first()['id'] ?? '');
        $rule = fn (EnrichmentProductRelation $r): ?string => $r->reasons['rule'] ?? collect((array) ($r->reasons['also'] ?? []))
            ->first(fn ($source): bool => ! str_starts_with((string) $source, 'merchant'));

        $ruleOfCategory = [];
        foreach ($relations as $relation) {
            if (($key = $rule($relation)) !== null) {
                $ruleOfCategory[$category($relation)] ??= $key;
            }
        }

        return $relations
            ->groupBy(fn (EnrichmentProductRelation $r): string => $rule($r) ?? $ruleOfCategory[$category($r)] ?? 'category:'.$category($r))
            ->map(fn (Collection $group): array => $group->values()->all())
            ->values()
            ->all();
    }

    /**
     * One of each kind in turn: a shelf shows a support and a wall fixing before a second support,
     * a lamp a battery and a charger.
     *
     * @param  list<list<EnrichmentProductRelation>>  $groups  from kinds()
     * @return Collection<int, EnrichmentProductRelation>
     */
    private static function varied(array $groups, int $limit): Collection
    {
        $varied = [];
        for ($round = 0; count($varied) < $limit && $groups !== []; $round++) {
            foreach ($groups as $g => $group) {
                if (! isset($group[$round])) {
                    unset($groups[$g]);

                    continue;
                }
                if (count($varied) < $limit) {
                    $varied[] = $group[$round];
                }
            }
        }

        return collect($varied);
    }

    /**
     * A store category to browse for each kind of related product: the category most products of
     * that kind share, the deepest and then the smallest when several tie ("shelf supports" before
     * "shelving" when every support is in both), below the top level (a top-level category such
     * as "sale" says nothing about the kind), with a page, and holding more than a section shows.
     *
     * @param  list<list<CatalogProduct>>  $kinds
     * @return list<array{id: string, title: string, url: string}>
     */
    private function browseLinks(array $kinds): array
    {
        $links = [];

        foreach ($kinds as $products) {
            $counts = [];
            foreach ($products as $product) {
                foreach ((array) ($product->payload['categories'] ?? []) as $category) {
                    $id = is_array($category) ? (string) ($category['id'] ?? '') : '';
                    if ($id !== '') {
                        $counts[$id] = ($counts[$id] ?? 0) + 1;
                    }
                }
            }

            $best = collect(array_keys($counts))
                ->map(fn ($id): ?CatalogCategory => $this->categories()->get((string) $id))
                ->filter(fn (?CatalogCategory $c): bool => $c !== null && $c->depth > 0 && (string) $c->url !== '' && $c->product_count > count($products))
                ->sort(fn (CatalogCategory $a, CatalogCategory $b): int => [$counts[$b->external_id], $b->depth, $a->product_count] <=> [$counts[$a->external_id], $a->depth, $b->product_count])
                ->first();

            if ($best !== null && ! isset($links[$best->external_id])) {
                $links[$best->external_id] = ['id' => $best->external_id, 'title' => $best->name, 'url' => (string) $best->url];
            }
        }

        return array_slice(array_values($links), 0, self::MAX_BROWSE_LINKS);
    }

    /** @return Collection<string, CatalogCategory> active categories by external id, per build */
    private function categories(): Collection
    {
        return $this->categories ??= CatalogCategory::query()->whereNull('removed_at')->get()->keyBy('external_id');
    }

    /** @return array<string, string|null> category external id => parent external id */
    private function categoryParents(): array
    {
        return $this->categories()->map(fn (CatalogCategory $c): ?string => $c->parent_external_id)->all();
    }

    /** @return array<string, array<string, string>> rule key => labels */
    private function ruleLabels(): array
    {
        $rules = EnrichmentRelationRules::query()->where('active', true)->orderByDesc('version')->value('definition');

        return collect((array) ($rules['rules'] ?? []))->mapWithKeys(fn (array $rule): array => [$rule['key'] => (array) ($rule['label'] ?? [])])->all();
    }

    /**
     * Guides for the product: articles matched to it and articles a checker approved for the same
     * jobs, kept and ranked by GuideRelevance.
     *
     * @param  list<string>  $uses
     * @return list<array<string, mixed>>
     */
    private function guides(CatalogProduct $product, array $uses): array
    {
        $matched = EnrichmentContentProduct::query()
            ->where('product_id', $product->id)
            ->orderByDesc('score')
            ->orderBy('rank')
            ->pluck('content_id')
            ->all();

        $forJobs = $uses === [] ? [] : EnrichmentFact::query()
            ->whereNotNull('content_id')
            ->where('kind', FactKind::Use)
            ->where('status', FactStatus::Approved)
            ->whereIn('value_text', $uses)
            ->orderBy('created_at')
            ->pluck('content_id')
            ->all();

        $ids = array_values(array_unique([...$matched, ...$forJobs]));
        if ($ids === []) {
            return [];
        }

        $articles = CatalogContent::query()->whereIn('id', $ids)->whereNull('removed_at')->get()->keyBy('id');
        $facts = EnrichmentFact::query()
            ->whereIn('content_id', $articles->keys())
            ->where('status', FactStatus::Approved)
            ->whereIn('kind', [FactKind::ContentKind, FactKind::ShopperValue, FactKind::Category, FactKind::Use])
            ->get(['content_id', 'kind', 'value_text'])
            ->groupBy('content_id');

        $relevance = new GuideRelevance($this->categoryParents());
        $productCategories = array_values(array_filter(array_map(fn ($c): string => is_array($c) ? (string) ($c['id'] ?? '') : '', (array) ($product->payload['categories'] ?? []))));

        $scored = [];
        foreach ($ids as $order => $id) {
            if (! $articles->has($id)) {
                continue;
            }

            $of = fn (FactKind $kind): array => $facts->get($id, collect())->where('kind', $kind)->pluck('value_text')->all();
            $score = $relevance->score($productCategories, $uses, [
                'kind' => $of(FactKind::ContentKind)[0] ?? null,
                'value' => $of(FactKind::ShopperValue)[0] ?? null,
                'categories' => $of(FactKind::Category),
                'uses' => $of(FactKind::Use),
                'matched' => in_array($id, $matched, true),
            ]);

            if ($score !== null) {
                $scored[] = ['article' => $articles->get($id), 'score' => $score, 'order' => $order];
            }
        }

        usort($scored, fn (array $a, array $b): int => [$b['score'], $a['order']] <=> [$a['score'], $b['order']]);

        return collect($scored)->pluck('article')->take(self::MAX_GUIDES)->map(fn (CatalogContent $c): array => [
            'id' => $c->external_id,
            'title' => $c->title,
            'url' => $c->url,
            'image' => $c->image_url,
        ])->values()->all();
    }

    /**
     * Specs from approved facts, then what code read that a shopper should know: the brand, what
     * to choose on the product page, the price unit, the pack size.
     *
     * @param  Collection<int, EnrichmentFact>  $facts
     * @param  array<string, mixed>  $reading
     * @return list<array{label: string, value: string, key?: string}>
     */
    private function specs(Collection $facts, mixed $definition, array $reading): array
    {
        $rows = [];
        $byKey = $facts->whereIn('kind', [FactKind::Type, FactKind::Spec, FactKind::Choice, FactKind::Flag])
            ->keyBy(fn (EnrichmentFact $f): string => $f->kind->value.'|'.$f->key);

        if ($definition !== null && $byKey->isNotEmpty()) {
            if (($type = $byKey->first(fn (EnrichmentFact $f): bool => $f->kind === FactKind::Type)) !== null) {
                $rows[] = ['key' => 'type', 'label' => __('widget::bank.type', [], $this->locale), 'value' => $definition->label('type', (string) $type->value_text, $this->locale)];
            }

            // What it is first (choices), then numbers, then yes/no features; each in vocabulary order.
            $order = [FactKind::Choice->value => 0, FactKind::Spec->value => 1, FactKind::Flag->value => 2];
            $attributes = $definition->attributes();
            usort($attributes, function (array $a, array $b) use ($byKey, $order): int {
                $kind = fn (array $attribute): int => $order[$byKey->first(fn (EnrichmentFact $f): bool => $f->key === $attribute['key'] && $f->kind !== FactKind::Type)?->kind->value] ?? 3;

                return $kind($a) <=> $kind($b);
            });

            foreach ($attributes as $attribute) {
                $fact = $byKey->first(fn (EnrichmentFact $f): bool => $f->key === $attribute['key'] && $f->kind !== FactKind::Type);

                if ($fact === null) {
                    continue;
                }

                $value = match ($fact->kind) {
                    FactKind::Spec => $this->measure((float) $fact->value_number, $fact->unit),
                    FactKind::Choice => $definition->label('attribute', $fact->key, $this->locale, (string) $fact->value_text),
                    default => __('widget::bank.yes', [], $this->locale),
                };

                $rows[] = ['key' => $attribute['key'], 'label' => $definition->label('attribute', $fact->key, $this->locale), 'value' => $value];
            }
        }

        if (isset($reading['brand']['brand'])) {
            $rows[] = ['key' => 'brand', 'label' => __('widget::bank.brand', [], $this->locale), 'value' => (string) $reading['brand']['brand']];
        }

        foreach ((array) ($reading['choices'] ?? []) as $choice) {
            $values = (array) $choice['values'];
            $rows[] = [
                'label' => __('widget::bank.to_choose', ['name' => $choice['name']], $this->locale),
                'value' => count($values) > 4 ? reset($values).' – '.end($values) : implode(', ', $values),
            ];
        }

        if (isset($reading['pack_count'])) {
            $rows[] = ['key' => 'pack_count', 'label' => __('widget::bank.pack', [], $this->locale), 'value' => (string) $reading['pack_count']];
        }

        if (isset($reading['price_unit'])) {
            $rows[] = ['label' => __('widget::bank.price_unit', [], $this->locale), 'value' => (string) $reading['price_unit']];
        }

        return array_slice($rows, 0, self::MAX_SPECS);
    }

    /**
     * What the widget remembers to compare this product with another of the same type later.
     *
     * @param  Collection<int, EnrichmentFact>  $facts
     * @param  list<array{label: string, value: string, key?: string}>  $specs
     * @return array<string, mixed>|null
     */
    private function compare(Collection $facts, mixed $definition, array $specs): ?array
    {
        $type = $facts->first(fn (EnrichmentFact $f): bool => $f->kind === FactKind::Type);

        if ($definition === null || $type === null) {
            return null;
        }

        $rows = [];
        foreach ($specs as $row) {
            if (isset($row['key']) && $row['key'] !== 'type') {
                $rows[] = [$row['key'], $row['label'], $row['value']];
            }
        }

        return $rows === [] ? null : [
            'key' => $definition->key().'|'.$type->value_text,
            'type' => $definition->label('type', (string) $type->value_text, $this->locale),
            'rows' => $rows,
        ];
    }

    /**
     * Whether the shopper must choose something on the product page before it can go in the cart.
     * Some stores keep "simple" products with an attribute to choose, such as a length, and a
     * plugin refuses the add without it.
     */
    private static function needsOptions(CatalogProduct $product): bool
    {
        if ($product->type !== 'simple') {
            return true;
        }

        foreach ($product->storeAttributes() as $attribute) {
            if ($attribute['for_variations'] && count($attribute['values']) > 1) {
                return true;
            }
        }

        return false;
    }

    private static function priceNote(CatalogProduct $product): ?string
    {
        $note = trim((string) ($product->payload['meta']['price_text'] ?? ''));

        return $note === '' ? null : mb_substr(strip_tags($note), 0, 40);
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
     * @param  array<string, string>  $chipReplace
     * @return array<string, mixed>
     */
    private function section(string $candidate, array $content, array $chipReplace = []): array
    {
        return [
            'candidate' => $candidate,
            'model' => self::MODELS[$candidate],
            'title' => __('widget::bank.titles.'.$candidate, [], $this->locale),
            'chip' => __('widget::bank.chips.'.$candidate, $chipReplace, $this->locale),
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
            default => (string) $section['chip'],
        };

        return ['candidate' => $section['candidate'], 'model' => $section['model'], 'text' => $text];
    }

    /** @return array<string, string> */
    private function labels(): array
    {
        return (array) __('widget::bank.ui', [], $this->locale);
    }
}
