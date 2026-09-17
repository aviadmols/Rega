<?php

namespace App\Modules\Analytics\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Analytics\Models\AnalyticsScore;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Facades\DB;

/**
 * What worked, once a night: a score for every widget section on the whole shop, on each page,
 * and for each product shown inside a section.
 *
 *   value   opens + 2 × clicks + 4 × adds to cart + 8 × purchases (a purchase counts double an add)
 *   module  value per exposure of the section across the shop
 *   page    value per exposure on that page, pulled toward the module's rate while the page has
 *           few exposures: (value + k × module rate) / (exposures + k)
 *   related value of one product inside a section per opening of that section on that page,
 *           pulled the same way toward the section's rate per opening
 *
 * With k exposures of prior, a page with two lucky clicks does not beat a section that works
 * across the shop, and a section nobody opens on a busy page sinks. The team's preview visits
 * do not count.
 */
final class ComputeScores
{
    public const AGENT = 'analytics.learner';

    public const ACTION = 'analytics.compute_scores';

    public const WEIGHTS = ['open' => 1, 'click' => 2, 'add' => 4, 'purchase' => 8];

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
        $days = (int) Settings::get('analytics.score_window_days', $shopId);
        $prior = (float) Settings::get('analytics.score_prior_exposures', $shopId);
        $since = now()->subDays($days);

        $modules = [];
        $pages = [];
        $related = [];

        $rows = AnalyticsEvent::query()
            ->where('occurred_at', '>=', $since)
            ->where('preview', false)
            ->whereNotNull('candidate_id')
            ->whereIn('type', ['exposure', 'open', 'click', 'add_to_cart'])
            ->select('candidate_id', 'type', 'page_type', 'product_external_id', 'content_external_id', 'item_external_id', 'source', 'result', DB::raw('count(*) as n'))
            ->groupBy('candidate_id', 'type', 'page_type', 'product_external_id', 'content_external_id', 'item_external_id', 'source', 'result')
            ->get();

        foreach ($rows as $row) {
            $counter = match ($row->type) {
                'exposure' => 'exposures',
                'open' => 'opens',
                'click' => 'clicks',
                default => $row->source === 'widget' && $row->result === 'added' ? 'adds' : null,
            };

            if ($counter === null) {
                continue;
            }

            $candidate = (string) $row->candidate_id;
            $page = $row->page_type.'|'.($row->page_type === 'content' ? $row->content_external_id : $row->product_external_id);
            $n = (int) $row->n;

            $modules[$candidate][$counter] = ($modules[$candidate][$counter] ?? 0) + $n;
            $pages[$candidate][$page][$counter] = ($pages[$candidate][$page][$counter] ?? 0) + $n;

            if (in_array($counter, ['clicks', 'adds'], true) && $row->item_external_id !== null) {
                $related[$candidate][$page][(string) $row->item_external_id][$counter] = ($related[$candidate][$page][(string) $row->item_external_id][$counter] ?? 0) + $n;
            }
        }

        // Purchases: an ordered product the same visitor added from a section before ordering.
        $purchases = 0;
        $attribution = (int) Settings::get('analytics.attribution_days', $shopId);

        foreach (AnalyticsOrder::query()->where('ordered_at', '>=', $since)->whereNotNull('visitor_hash')->whereNotNull('attributed_items')->get() as $order) {
            foreach ((array) $order->attributed_items as $item) {
                $add = AnalyticsEvent::query()
                    ->where('visitor_hash', $order->visitor_hash)
                    ->where('type', 'add_to_cart')->where('source', 'widget')->where('result', 'added')
                    ->where('item_external_id', (string) $item)
                    ->whereNotNull('candidate_id')
                    ->whereBetween('occurred_at', [$order->ordered_at->copy()->subDays($attribution), $order->ordered_at->copy()->addMinutes(10)])
                    ->latest('occurred_at')
                    ->first();

                if ($add === null) {
                    continue;
                }

                $candidate = (string) $add->candidate_id;
                $page = $add->page_type.'|'.($add->page_type === 'content' ? $add->content_external_id : $add->product_external_id);
                $modules[$candidate]['purchases'] = ($modules[$candidate]['purchases'] ?? 0) + 1;
                $pages[$candidate][$page]['purchases'] = ($pages[$candidate][$page]['purchases'] ?? 0) + 1;
                $related[$candidate][$page][(string) $item]['purchases'] = ($related[$candidate][$page][(string) $item]['purchases'] ?? 0) + 1;
                $purchases++;
            }
        }

        $now = now();
        $scores = [];

        foreach ($modules as $candidate => $counts) {
            $value = self::value($counts);
            $moduleRate = $value / max(1, $counts['exposures'] ?? 0);
            $scores[] = self::row($shopId, AnalyticsScore::SCOPE_MODULE, $candidate, '', '', '', $counts, $value, $moduleRate, $now);

            $relatedValue = 0;
            $relatedOpens = 0;
            foreach ($related[$candidate] ?? [] as $page => $items) {
                $relatedOpens += $pages[$candidate][$page]['opens'] ?? 0;
                foreach ($items as $itemCounts) {
                    $relatedValue += self::value($itemCounts);
                }
            }
            $relatedRate = $relatedValue / max(1, $relatedOpens);

            foreach ($pages[$candidate] ?? [] as $page => $pageCounts) {
                [$pageType, $pageId] = explode('|', $page, 2);
                $pageValue = self::value($pageCounts);
                $pageScore = ($pageValue + $prior * $moduleRate) / (($pageCounts['exposures'] ?? 0) + $prior);
                $scores[] = self::row($shopId, AnalyticsScore::SCOPE_PAGE, $candidate, $pageType, $pageId, '', $pageCounts, $pageValue, $pageScore, $now);

                foreach ($related[$candidate][$page] ?? [] as $item => $itemCounts) {
                    $itemValue = self::value($itemCounts);
                    $opens = $pageCounts['opens'] ?? 0;
                    $itemScore = ($itemValue + $prior * $relatedRate) / ($opens + $prior);
                    $scores[] = self::row($shopId, AnalyticsScore::SCOPE_RELATED, $candidate, $pageType, $pageId, $item, ['exposures' => $opens] + $itemCounts, $itemValue, $itemScore, $now);
                }
            }
        }

        DB::transaction(function () use ($scores): void {
            AnalyticsScore::query()->delete();

            foreach (array_chunk($scores, 500) as $chunk) {
                AnalyticsScore::query()->insert($chunk);
            }
        });

        $ranked = collect($scores)->where('scope', AnalyticsScore::SCOPE_MODULE)->sortByDesc('score')->values();

        $run->output([
            'window_days' => $days,
            'prior_exposures' => $prior,
            'events' => (int) $rows->sum('n'),
            'purchases' => $purchases,
            'scores' => count($scores),
            'modules' => $ranked->map(fn (array $s): array => ['module' => $s['candidate'], 'exposures' => $s['exposures'], 'value' => $s['value'], 'score' => round($s['score'], 4)])->all(),
        ])->summary('analytics::runs.scores_computed', [
            'modules' => number_format($ranked->count()),
            'pages' => number_format(collect($scores)->where('scope', AnalyticsScore::SCOPE_PAGE)->count()),
            'related' => number_format(collect($scores)->where('scope', AnalyticsScore::SCOPE_RELATED)->count()),
            'purchases' => number_format($purchases),
            'days' => $days,
        ]);
    }

    /** @param array<string, int> $counts */
    public static function value(array $counts): int
    {
        return ($counts['opens'] ?? 0) * self::WEIGHTS['open']
            + ($counts['clicks'] ?? 0) * self::WEIGHTS['click']
            + ($counts['adds'] ?? 0) * self::WEIGHTS['add']
            + ($counts['purchases'] ?? 0) * self::WEIGHTS['purchase'];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private static function row(string $shopId, string $scope, string $candidate, string $pageType, string $pageId, string $related, array $counts, int $value, float $score, mixed $now): array
    {
        return [
            'shop_id' => $shopId,
            'scope' => $scope,
            'candidate' => mb_substr($candidate, 0, 80),
            'page_type' => $pageType,
            'page_external_id' => mb_substr($pageId, 0, 64),
            'related_external_id' => mb_substr($related, 0, 64),
            'exposures' => $counts['exposures'] ?? 0,
            'opens' => $counts['opens'] ?? 0,
            'clicks' => $counts['clicks'] ?? 0,
            'adds' => $counts['adds'] ?? 0,
            'purchases' => $counts['purchases'] ?? 0,
            'value' => $value,
            'score' => round($score, 5),
            'computed_at' => $now,
        ];
    }
}
