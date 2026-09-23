<?php

namespace App\Modules\Analytics\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsPrior;
use App\Modules\Analytics\Models\AnalyticsScore;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\DB;

/**
 * How each panel tends to do across the shops of one trade.
 *
 * A shop that opened this morning has no scores, so it shows the panels in whatever order the
 * code happens to build them. Other shops of the same trade have been watched for months, and
 * what they learned is almost certainly closer to right than the order of a foreach loop.
 *
 * Only rates cross: how often a panel was opened after being seen, summed over shops. No shop is
 * named, no product and no person appears, and a trade with too few shops produces nothing at all
 * rather than passing one shop's habits off as its trade's.
 */
final class ComputePriors
{
    public const AGENT = 'analytics.priors';

    public const ACTION = 'analytics.compute_priors';

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: null,
            work: function (RunContext $run): void {
                $need = (int) Settings::get('analytics.prior_min_shops');
                $written = $this->tenant->runUnscoped(fn (): array => $this->compute($need));

                $run->output(['trades' => count($written), 'rows' => array_sum($written)])
                    ->summary('analytics::runs.priors', [
                        'trades' => (string) count($written),
                        'rows' => (string) array_sum($written),
                    ]);
            },
        );
    }

    /** @return array<string, int> trade => rows written */
    private function compute(int $need): array
    {
        $verticals = Shop::query()->whereNotNull('vertical')->pluck('vertical', 'id')
            ->map(fn ($v): string => is_string($v) ? $v : $v->value);

        if ($verticals->isEmpty()) {
            return [];
        }

        $rows = AnalyticsScore::query()
            ->where('scope', AnalyticsScore::SCOPE_MODULE)
            ->whereIn('shop_id', $verticals->keys())
            ->get(['shop_id', 'candidate', 'exposures', 'opens', 'clicks']);

        $totals = [];

        foreach ($rows as $row) {
            $trade = $verticals[$row->shop_id] ?? null;

            if ($trade === null) {
                continue;
            }

            $key = $trade.'|'.$row->candidate;
            $totals[$key] ??= ['vertical' => $trade, 'candidate' => (string) $row->candidate, 'shops' => [], 'exposures' => 0, 'opens' => 0, 'clicks' => 0];
            $totals[$key]['shops'][$row->shop_id] = true;
            $totals[$key]['exposures'] += (int) $row->exposures;
            $totals[$key]['opens'] += (int) $row->opens;
            $totals[$key]['clicks'] += (int) $row->clicks;
        }

        $written = [];

        DB::transaction(function () use ($totals, $need, &$written): void {
            AnalyticsPrior::query()->delete();

            foreach ($totals as $total) {
                $shops = count($total['shops']);

                // One shop's habits are not its trade's.
                if ($shops < $need || $total['exposures'] === 0) {
                    continue;
                }

                AnalyticsPrior::query()->create([
                    'vertical' => $total['vertical'],
                    'candidate' => $total['candidate'],
                    'shops' => $shops,
                    'exposures' => $total['exposures'],
                    'opens' => $total['opens'],
                    'clicks' => $total['clicks'],
                    // Opening says the panel was worth a look; clicking says it was worth acting
                    // on, and is worth more.
                    'score' => round(($total['opens'] + 2 * $total['clicks']) / $total['exposures'], 6),
                    'computed_at' => now(),
                ]);

                $written[$total['vertical']] = ($written[$total['vertical']] ?? 0) + 1;
            }
        });

        return $written;
    }
}
