<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\RelationKind;
use App\Modules\Enrichment\Models\EnrichmentCodeReading;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentProductRelation;
use App\Modules\Enrichment\Models\EnrichmentRelationRules;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Relations\RelationRuleSet;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What to show with each product, in code, from approved facts and the store's own links.
 * Every relation keeps the reasons it was chosen, for the log and for tuning.
 *
 *   complement   the merchant's cross-sells; products whose merchant links point to this one;
 *                the shop's rules (a battery of the same brand and voltage for a cordless tool,
 *                oil for wood meant for outdoor jobs)
 *   family       the same product in other sizes (see ProductFamily)
 *   alternative  the same product type and power source, at a similar price
 *
 * Stock is checked here and again, live, in the widget.
 */
final class ComputeProductRelations
{
    public const AGENT = 'enrichment.relator';

    public const ACTION = 'enrichment.compute_relations';

    private const MERCHANT_LIMIT = 6;

    private const RULE_LIMIT = 4;

    private const FAMILY_LIMIT = 12;

    private const ALTERNATIVE_LIMIT = 8;

    /** Alternatives cost between 60% and 160% of the product. */
    private const PRICE_BAND = [0.6, 1.6];

    private const SAMPLES = 25;

    /** @var array<string, array<string, mixed>> key "product|related|kind" => row */
    private array $rows = [];

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(string $shopId): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->compute($run, $shopId)),
        );
    }

    private function compute(RunContext $run, string $shopId): void
    {
        $this->rows = [];
        $products = CatalogProduct::query()->whereNull('removed_at')->get(['id', 'external_id', 'title', 'price', 'in_stock', 'purchasable', 'type', 'payload']);
        $profiles = $this->profiles($products);
        $byExternal = $products->keyBy('external_id');
        $ruleStats = [];

        // 1. The merchant's own cross-sells, in the merchant's order.
        foreach ($products as $product) {
            $position = 0;
            foreach ($product->merchantRelations() as $relation) {
                $related = $byExternal->get($relation['target']);

                if ($relation['type'] === 'cross_sell' && $related !== null && $related->id !== $product->id && $position < self::MERCHANT_LIMIT) {
                    $this->add($product->id, $related->id, RelationKind::Complement, 'merchant', 150 - $position++, ['merchant' => 'cross_sell']);
                }
            }
        }

        // 2. Merchant links pointing the other way: a battery that lists this tool as an upsell.
        $reverse = [];
        foreach ($products as $product) {
            foreach ($product->merchantRelations() as $relation) {
                $target = $byExternal->get($relation['target']);

                if ($target !== null && $target->id !== $product->id) {
                    $reverse[$target->id][] = [$product, $relation['type']];
                }
            }
        }

        foreach ($reverse as $productId => $links) {
            foreach (array_slice($links, 0, self::MERCHANT_LIMIT) as $i => [$linking, $type]) {
                $this->add($productId, $linking->id, RelationKind::Complement, 'merchant_reverse', 110 - $i, ['merchant' => 'links_to_this', 'link' => $type]);
            }
        }

        // 3. The shop's rules.
        $rules = EnrichmentRelationRules::query()->where('active', true)->orderByDesc('version')->first();

        foreach ($rules?->rules()->rules() ?? [] as $rule) {
            $ruleStats[$rule['key']] = $this->applyRule($rule, $profiles);
        }

        // 4. Other sizes of the same product.
        $families = collect($profiles)->filter(fn (array $p): bool => $p['family'] !== null)->groupBy('family');

        foreach ($families as $members) {
            if ($members->count() < 2) {
                continue;
            }

            $sorted = $members->sortBy('title', SORT_NATURAL)->values();

            foreach ($sorted as $member) {
                foreach ($sorted->where('id', '!=', $member['id'])->take(self::FAMILY_LIMIT)->values() as $i => $other) {
                    $this->add($member['id'], $other['id'], RelationKind::Family, 'family', 100 - $i, ['family' => $member['family_name']]);
                }
            }
        }

        // 5. Alternatives: same vocabulary, type and power source, similar price, not the same family.
        $groups = collect($profiles)
            ->filter(fn (array $p): bool => $p['type'] !== null && $p['type'] !== 'other' && $p['price'] > 0)
            ->groupBy(fn (array $p): string => $p['vocabulary'].'|'.$p['type'].'|'.($p['choices']['power_source'] ?? '-'));

        foreach ($groups as $group) {
            foreach ($group as $profile) {
                $candidates = $group
                    ->filter(fn (array $o): bool => $o['id'] !== $profile['id'] && ($o['family'] === null || $o['family'] !== $profile['family']))
                    ->map(fn (array $o): array => $o + ['ratio' => $o['price'] / $profile['price']])
                    ->filter(fn (array $o): bool => $o['ratio'] >= self::PRICE_BAND[0] && $o['ratio'] <= self::PRICE_BAND[1])
                    ->sortBy(fn (array $o): array => [abs(log($o['ratio'])), $o['external_id']])
                    ->take(self::ALTERNATIVE_LIMIT)
                    ->values();

                foreach ($candidates as $o) {
                    $this->add($profile['id'], $o['id'], RelationKind::Alternative, 'same_type', 100 - (int) round(abs(log($o['ratio'])) * 100), [
                        'type' => $profile['type'],
                        'power_source' => $profile['choices']['power_source'] ?? null,
                        'price_ratio' => round($o['ratio'], 2),
                    ]);
                }
            }
        }

        $now = now();

        DB::transaction(function () use ($shopId, $now): void {
            EnrichmentProductRelation::query()->delete();

            foreach (array_chunk(array_values($this->rows), 500) as $chunk) {
                EnrichmentProductRelation::query()->insert(array_map(fn (array $row): array => [
                    'id' => (string) Str::ulid(),
                    'shop_id' => $shopId,
                    'product_id' => $row['product_id'],
                    'related_product_id' => $row['related_product_id'],
                    'kind' => $row['kind'],
                    'source' => $row['source'],
                    'score' => $row['score'],
                    'reasons' => json_encode($row['reasons'], JSON_UNESCAPED_UNICODE),
                    'computed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }
        });

        $rows = collect($this->rows);
        $titles = $products->pluck('title', 'id');

        $run->output([
            'relations' => $rows->count(),
            'by_kind' => $rows->countBy('kind')->all(),
            'by_source' => $rows->countBy('source')->all(),
            'products_with_complements' => $rows->where('kind', RelationKind::Complement->value)->pluck('product_id')->unique()->count(),
            'rules_version' => $rules?->version,
            'rules' => $ruleStats,
            'samples' => $rows->where('kind', RelationKind::Complement->value)->take(self::SAMPLES)->map(fn (array $row): array => [
                'product' => mb_substr((string) $titles->get($row['product_id']), 0, 60),
                'related' => mb_substr((string) $titles->get($row['related_product_id']), 0, 60),
                'source' => $row['source'],
                'reasons' => $row['reasons'],
            ])->values()->all(),
        ])->summary('enrichment::runs.relations_computed', [
            'relations' => number_format($rows->count()),
            'complements' => number_format($rows->where('kind', RelationKind::Complement->value)->count()),
            'products' => number_format($rows->where('kind', RelationKind::Complement->value)->pluck('product_id')->unique()->count()),
            'families' => number_format($rows->where('kind', RelationKind::Family->value)->count()),
            'alternatives' => number_format($rows->where('kind', RelationKind::Alternative->value)->count()),
        ]);
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, array<string, mixed>>  $profiles
     * @return array{from: int, related: int}
     */
    private function applyRule(array $rule, array $profiles): array
    {
        $from = array_filter($profiles, fn (array $p): bool => RelationRuleSet::meets((array) $rule['from'], $p));
        $to = array_filter($profiles, fn (array $p): bool => $p['in_stock'] && RelationRuleSet::meets((array) $rule['to'], $p));
        $kind = RelationKind::from($rule['kind']);
        $limit = (int) ($rule['limit'] ?? self::RULE_LIMIT);
        $match = (array) ($rule['match'] ?? []);
        $related = 0;

        foreach ($from as $product) {
            $boosted = isset($rule['boost']['from_choices']) && RelationRuleSet::meets(['choices' => $rule['boost']['from_choices']], $product);
            $candidates = [];

            foreach ($to as $candidate) {
                if ($candidate['id'] === $product['id'] || ($candidate['family'] !== null && $candidate['family'] === $product['family'])) {
                    continue;
                }

                $reasons = $this->matches($match, $product, $candidate);

                if ($reasons === null) {
                    continue;
                }

                $candidates[] = [$candidate, $reasons];
            }

            usort($candidates, fn (array $a, array $b): int => [$b[1]['_strength'], $a[0]['price'], $a[0]['external_id']] <=> [$a[1]['_strength'], $b[0]['price'], $b[0]['external_id']]);

            foreach (array_slice($candidates, 0, $limit) as $i => [$candidate, $reasons]) {
                unset($reasons['_strength']);
                $this->add($product['id'], $candidate['id'], $kind, $rule['key'], ($boosted ? 140 : 100) - $i, ['rule' => $rule['key']] + $reasons);
                $related++;
            }
        }

        return ['from' => count($from), 'related' => $related];
    }

    /**
     * The conditions a rule sets between two products, or null when one fails.
     *
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>|null
     */
    private function matches(array $match, array $a, array $b): ?array
    {
        $reasons = ['_strength' => 0];

        if (($match['brand'] ?? false) === true) {
            if ($a['brand'] === null || $b['brand'] === null || mb_strtolower($a['brand']) !== mb_strtolower($b['brand'])) {
                return null;
            }
            $reasons['brand'] = $a['brand'];
            $reasons['_strength']++;
        }

        foreach ((array) ($match['specs'] ?? []) as $key) {
            $x = $a['specs'][$key] ?? null;
            $y = $b['specs'][$key] ?? null;

            if ($x === null || $y === null || abs($x - $y) > 0.02 * max(abs($x), abs($y))) {
                return null;
            }
            $reasons[$key] = $x;
            $reasons['_strength']++;
        }

        // A charger may not state its voltage; when both do, they must agree.
        foreach ((array) ($match['specs_if_known'] ?? []) as $key) {
            $x = $a['specs'][$key] ?? null;
            $y = $b['specs'][$key] ?? null;

            if ($x !== null && $y !== null) {
                if (abs($x - $y) > 0.02 * max(abs($x), abs($y))) {
                    return null;
                }
                $reasons[$key] = $x;
                $reasons['_strength']++;
            }
        }

        if (($match['uses'] ?? false) === true) {
            $shared = array_values(array_intersect((array) $a['uses'], (array) $b['uses']));

            if ($shared === []) {
                return null;
            }
            $reasons['uses'] = $shared;
            $reasons['_strength'] += count($shared);
        }

        return $reasons;
    }

    /**
     * Each product as the rules see it: approved facts and code's reading.
     *
     * @param  Collection<int, CatalogProduct>  $products
     * @return array<string, array<string, mixed>>
     */
    private function profiles(Collection $products): array
    {
        $vocabularyKeys = EnrichmentVocabulary::query()->pluck('key', 'id');
        $readings = EnrichmentCodeReading::query()->get(['product_id', 'vocabulary_id', 'brand', 'family_key', 'reading'])->keyBy('product_id');
        $profiles = [];

        foreach ($products as $product) {
            $reading = $readings->get($product->id);

            $profiles[$product->id] = [
                'id' => $product->id,
                'external_id' => $product->external_id,
                'title' => $product->title,
                'price' => (float) ($product->price ?? 0),
                'in_stock' => $product->in_stock && $product->purchasable,
                'vocabulary' => $reading?->vocabulary_id === null ? null : $vocabularyKeys->get($reading->vocabulary_id),
                'brand' => $reading?->brand,
                'family' => $reading?->family_key,
                'family_name' => $reading?->reading['family']['name'] ?? null,
                'type' => null,
                'choices' => [],
                'specs' => [],
                'uses' => [],
                'categories' => array_values(array_filter(array_map(fn ($c): string => is_array($c) ? (string) ($c['id'] ?? '') : '', (array) ($product->payload['categories'] ?? [])))),
            ];
        }

        EnrichmentFact::query()
            ->whereNotNull('product_id')
            ->where('status', FactStatus::Approved)
            ->whereIn('kind', [FactKind::Type, FactKind::Choice, FactKind::Spec, FactKind::Use])
            ->orderBy('created_at')
            ->orderBy('id')
            ->each(function (EnrichmentFact $fact) use (&$profiles, $vocabularyKeys): void {
                if (! isset($profiles[$fact->product_id])) {
                    return;
                }

                $profile = &$profiles[$fact->product_id];
                $profile['vocabulary'] ??= $fact->vocabulary_id === null ? null : $vocabularyKeys->get($fact->vocabulary_id);

                match ($fact->kind) {
                    FactKind::Type => $profile['type'] = (string) $fact->value_text,
                    FactKind::Choice => $profile['choices'][$fact->key] = (string) $fact->value_text,
                    FactKind::Spec => $profile['specs'][$fact->key] = (float) $fact->value_number,
                    default => $profile['uses'][] = (string) $fact->value_text,
                };
            });

        return $profiles;
    }

    /** @param array<string, mixed> $reasons */
    private function add(string $productId, string $relatedId, RelationKind $kind, string $source, int $score, array $reasons): void
    {
        $key = $productId.'|'.$relatedId.'|'.$kind->value;

        if (isset($this->rows[$key])) {
            // Found by several sources: keep the best score and every reason.
            $this->rows[$key]['score'] = max($this->rows[$key]['score'], $score) + 5;
            $this->rows[$key]['reasons']['also'][] = $source;

            return;
        }

        $this->rows[$key] = [
            'product_id' => $productId,
            'related_product_id' => $relatedId,
            'kind' => $kind->value,
            'source' => $source,
            'score' => $score,
            'reasons' => $reasons,
        ];
    }
}
