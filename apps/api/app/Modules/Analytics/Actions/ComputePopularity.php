<?php

namespace App\Modules\Analytics\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Analytics\Models\AnalyticsPopularity;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Facades\DB;

/**
 * How wanted each product is, once a night: adds to the cart (the store's own button and the
 * widget's, successful ones only) and orders that contained it, over the last weeks.
 *
 *   score    adds + 2 × orders, an order counting double an add as in ComputeScores
 *   rank     1 for the highest score in the shop
 *   popular  score of at least analytics.popular_min_score, and a rank within the top
 *            analytics.popular_top_percent of the products the store sells now
 *
 * The share is taken of the whole live catalog, not of the products with activity, so in a quiet
 * week one product with five adds is not "popular" for being the only one anyone touched. The
 * team's preview visits do not count.
 */
final class ComputePopularity
{
    public const AGENT = ComputeScores::AGENT;

    public const ACTION = 'analytics.compute_popularity';

    public const ORDER_WEIGHT = 2;

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
        $days = (int) Settings::get('analytics.popularity_window_days', $shopId);
        $minScore = (int) Settings::get('analytics.popular_min_score', $shopId);
        $topPercent = (int) Settings::get('analytics.popular_top_percent', $shopId);
        $since = now()->subDays($days);

        /** @var array<string, array{adds: int, orders: int, units: int}> $counts */
        $counts = [];
        $touch = function (string $product) use (&$counts): void {
            $counts[$product] ??= ['adds' => 0, 'orders' => 0, 'units' => 0];
        };

        $adds = AnalyticsEvent::query()
            ->where('type', 'add_to_cart')
            ->where('result', 'added')
            ->where('preview', false)
            ->where('occurred_at', '>=', $since)
            ->whereNotNull('item_external_id')
            ->select('item_external_id', DB::raw('count(*) as n'))
            ->groupBy('item_external_id')
            ->get();

        foreach ($adds as $row) {
            $touch((string) $row->item_external_id);
            $counts[(string) $row->item_external_id]['adds'] += (int) $row->n;
        }

        $orders = 0;
        AnalyticsOrder::query()->where('ordered_at', '>=', $since)->orderBy('id')
            ->chunk(200, function ($chunk) use (&$counts, &$orders, $touch): void {
                foreach ($chunk as $order) {
                    $orders++;
                    $seen = [];
                    foreach ((array) $order->items as $item) {
                        $product = (string) ($item['product_id'] ?? '');
                        if ($product === '') {
                            continue;
                        }
                        $touch($product);
                        $counts[$product]['units'] += max(1, (int) ($item['quantity'] ?? 1));
                        if (! isset($seen[$product])) {
                            $counts[$product]['orders']++;
                            $seen[$product] = true;
                        }
                    }
                }
            });

        $catalog = CatalogProduct::query()->whereNull('removed_at')->count();
        $topRanks = max(1, (int) ceil($catalog * $topPercent / 100));

        $ranked = collect($counts)
            ->map(fn (array $c, string $product): array => $c + ['product' => $product, 'score' => $c['adds'] + self::ORDER_WEIGHT * $c['orders']])
            ->sortBy([['score', 'desc'], ['orders', 'desc'], ['adds', 'desc'], ['product', 'asc']])
            ->values();

        $now = now();
        $rows = $ranked->map(fn (array $c, int $i): array => [
            'shop_id' => $shopId,
            'product_external_id' => mb_substr($c['product'], 0, 64),
            'adds' => $c['adds'],
            'orders' => $c['orders'],
            'units' => $c['units'],
            'score' => $c['score'],
            'rank' => $i + 1,
            'popular' => $c['score'] >= $minScore && $i < $topRanks,
            'window_days' => $days,
            'computed_at' => $now,
        ])->all();

        DB::transaction(function () use ($rows): void {
            AnalyticsPopularity::query()->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                AnalyticsPopularity::query()->insert($chunk);
            }
        });

        $popular = count(array_filter($rows, fn (array $r): bool => $r['popular']));

        $run->output([
            'window_days' => $days,
            'min_score' => $minScore,
            'top_percent' => $topPercent,
            'catalog_products' => $catalog,
            'top_ranks' => $topRanks,
            'orders' => $orders,
            'products_with_activity' => count($rows),
            'popular' => $popular,
            'top' => array_map(fn (array $r): array => ['product' => $r['product_external_id'], 'adds' => $r['adds'], 'orders' => $r['orders'], 'score' => $r['score']], array_slice($rows, 0, 20)),
        ])->summary('analytics::runs.popularity_computed', [
            'products' => number_format(count($rows)),
            'popular' => number_format($popular),
            'orders' => number_format($orders),
            'days' => $days,
        ]);
    }
}
