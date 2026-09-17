<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentRanking;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Scanning\Units;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Facades\DB;

/**
 * Superlatives, in code only: "the lightest of 9 cordless jigsaws in stock".
 *
 * A comparable set is one product type plus the vocabulary's set_by choices (cordless apart
 * from corded). Price is further split by price_set_by (a body alone apart from a kit). Only
 * approved facts, only products in stock, and only sets with at least min_set_size products.
 * Equal values share a rank and are marked tied, so the text can say "among the lightest".
 */
final class ComputeRankings
{
    public const AGENT = 'enrichment.ranker';

    public const ACTION = 'enrichment.compute_rankings';

    /** Ranks kept per metric: first, second and third. */
    public const TOP = 3;

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
        $minSetSize = (int) Settings::get('enrichment.min_set_size', $shopId);
        $now = now();
        $rows = [];
        $sets = 0;

        foreach (EnrichmentVocabulary::query()->where('active', true)->get() as $vocabularyModel) {
            $vocabulary = $vocabularyModel->definition();
            $profiles = $this->profiles($vocabularyModel->id);

            if ($profiles === []) {
                continue;
            }

            $products = CatalogProduct::query()
                ->whereKey(array_keys($profiles))
                ->whereNull('removed_at')
                ->where('in_stock', true)
                ->get(['id', 'price'])
                ->keyBy('id');

            $groups = [];
            foreach ($profiles as $productId => $profile) {
                if (! $products->has($productId) || ! isset($profile['type'])) {
                    continue;
                }

                $facets = ['type' => $profile['type']];
                foreach ($vocabulary->setBy() as $key) {
                    if (! isset($profile['choices'][$key])) {
                        continue 2;
                    }
                    $facets[$key] = $profile['choices'][$key];
                }

                $groups[$this->setKey($vocabulary->key(), $facets)]['facets'] = $facets;
                $groups[$this->setKey($vocabulary->key(), $facets)]['products'][] = $productId;
            }

            foreach ($groups as $setKey => $group) {
                $sets++;

                foreach ($vocabulary->attributes() as $attribute) {
                    if (($attribute['type'] ?? 'number') !== 'number' || ! isset($attribute['rank'])) {
                        continue;
                    }

                    $values = [];
                    foreach ($group['products'] as $productId) {
                        if (isset($profiles[$productId]['specs'][$attribute['key']])) {
                            $values[$productId] = $profiles[$productId]['specs'][$attribute['key']];
                        }
                    }

                    $rows = [...$rows, ...$this->rank($shopId, $setKey, $group['facets'], $attribute['key'], $attribute['rank'], Units::CANONICAL[$attribute['dimension']] ?? null, $values, $minSetSize, $now)];
                }

                // Price, split again by what is in the box.
                $priceGroups = [];
                foreach ($group['products'] as $productId) {
                    $price = $products->get($productId)?->price;

                    if ($price === null || (float) $price <= 0) {
                        continue;
                    }

                    $facets = $group['facets'];
                    foreach ($vocabulary->priceSetBy() as $key) {
                        if (! isset($profiles[$productId]['choices'][$key])) {
                            continue 2;
                        }
                        $facets[$key] = $profiles[$productId]['choices'][$key];
                    }

                    $priceKey = $this->setKey($vocabulary->key(), $facets);
                    $priceGroups[$priceKey]['facets'] = $facets;
                    $priceGroups[$priceKey]['values'][$productId] = (float) $price;
                }

                foreach ($priceGroups as $priceKey => $priceGroup) {
                    $rows = [...$rows, ...$this->rank($shopId, $priceKey, $priceGroup['facets'], 'price', 'min', null, $priceGroup['values'], $minSetSize, $now)];
                }
            }
        }

        DB::transaction(function () use ($rows): void {
            EnrichmentRanking::query()->delete();

            foreach (array_chunk($rows, 200) as $chunk) {
                foreach ($chunk as $row) {
                    EnrichmentRanking::query()->create($row);
                }
            }
        });

        $run->output(['sets' => $sets, 'rankings' => count($rows), 'products' => count(array_unique(array_column($rows, 'product_id')))])
            ->summary('enrichment::runs.rankings_computed', [
                'rankings' => number_format(count($rows)),
                'products' => number_format(count(array_unique(array_column($rows, 'product_id')))),
                'sets' => number_format($sets),
                'min' => $minSetSize,
            ]);
    }

    /**
     * Approved facts per product: type, spec values, choice values.
     *
     * @return array<string, array{type?: string, specs: array<string, float>, choices: array<string, string>}>
     */
    private function profiles(string $vocabularyId): array
    {
        $profiles = [];

        EnrichmentFact::query()
            ->where('vocabulary_id', $vocabularyId)
            ->whereNotNull('product_id')
            ->where('status', FactStatus::Approved)
            ->whereIn('kind', [FactKind::Type, FactKind::Spec, FactKind::Choice])
            ->orderBy('created_at')
            ->each(function (EnrichmentFact $fact) use (&$profiles): void {
                $profile = $profiles[$fact->product_id] ?? ['specs' => [], 'choices' => []];

                match ($fact->kind) {
                    FactKind::Type => $profile['type'] = (string) $fact->value_text,
                    FactKind::Spec => $profile['specs'][$fact->key] = (float) $fact->value_number,
                    default => $profile['choices'][$fact->key] = (string) $fact->value_text,
                };

                $profiles[$fact->product_id] = $profile;
            });

        return $profiles;
    }

    /**
     * @param  array<string, string>  $facets
     * @param  array<string, float>  $values  product id => value
     * @return list<array<string, mixed>>
     */
    private function rank(string $shopId, string $setKey, array $facets, string $metric, string $direction, ?string $unit, array $values, int $minSetSize, mixed $now): array
    {
        if (count($values) < $minSetSize) {
            return [];
        }

        $rows = [];
        $counts = array_count_values(array_map(fn (float $v): string => (string) round($v, 6), $values));

        foreach ($values as $productId => $value) {
            $better = count(array_filter($values, fn (float $other): bool => $direction === 'min' ? $other < $value : $other > $value));
            $rank = $better + 1;

            if ($rank > self::TOP) {
                continue;
            }

            $rows[] = [
                'shop_id' => $shopId,
                'product_id' => $productId,
                'metric' => $metric,
                'direction' => $direction,
                'rank' => $rank,
                'tied' => $counts[(string) round($value, 6)] > 1,
                'set_size' => count($values),
                'set_key' => mb_substr($setKey, 0, 255),
                'set_facets' => $facets,
                'value' => round($value, 6),
                'unit' => $unit,
                'computed_at' => $now,
            ];
        }

        return $rows;
    }

    /** @param array<string, string> $facets */
    private function setKey(string $vocabularyKey, array $facets): string
    {
        return $vocabularyKey.'|'.implode('|', array_map(fn (string $k, string $v): string => "{$k}={$v}", array_keys($facets), $facets));
    }
}
