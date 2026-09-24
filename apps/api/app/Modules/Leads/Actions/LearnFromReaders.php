<?php

namespace App\Modules\Leads\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadCta;
use App\Modules\Leads\Models\LeadReview;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Facades\DB;

/**
 * What readers made of each version, and whether the reviewer saw it coming.
 *
 * Two jobs, and the second is the one that matters. The first is ordinary: count how often each
 * version was seen and clicked, and let the widget show the one that works. The second is the
 * reviewer's report card — the scores it gave, set against what happened next.
 *
 * A model scoring another model is easy to add and easy to believe in, and there is no reason to
 * believe in it until its eighties are clicked more often than its seventies. If they are not,
 * the screen says so, and somebody can lower the bar or change the model rather than carrying on
 * with a critic nobody has checked.
 */
final class LearnFromReaders
{
    public const AGENT = 'leads.learner';

    public const ACTION = 'leads.learn';

    /** How far back a version's showing is counted. */
    private const DAYS = 30;

    /** Below this many exposures a rate is a rumour. */
    private const ENOUGH = 40;

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
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->learn($run)),
        );
    }

    private function learn(RunContext $run): void
    {
        $seen = $this->counted();
        $leads = $this->leadsByCta();
        $scored = 0;

        foreach (LeadReview::query()->lazyById(200) as $review) {
            $cta = LeadCta::query()->find($review->cta_id);

            if ($cta === null) {
                continue;
            }

            $review->forceFill([
                'exposures' => $seen[$cta->variant]['exposures'] ?? 0,
                'clicks' => $seen[$cta->variant]['clicks'] ?? 0,
                'leads' => $leads[$cta->id] ?? 0,
                'measured_at' => now(),
            ])->save();
            $scored++;
        }

        $run->output([
            'versions' => count($seen),
            'reviews' => $scored,
            'calibration' => $this->calibration(),
        ])->summary('leads::runs.learned', ['versions' => (string) count($seen)]);
    }

    /**
     * How often each version was seen and clicked.
     *
     * The version rides on the event's model field, which is where the scoring already keeps what
     * produced a panel, so nothing new had to be carried to record it.
     *
     * @return array<string, array{exposures: int, clicks: int}>
     */
    private function counted(): array
    {
        $rows = AnalyticsEvent::query()
            ->where('candidate_id', 'cta')
            ->where('preview', false)
            ->where('holdout', false)
            ->where('occurred_at', '>=', now()->subDays(self::DAYS))
            ->selectRaw('model, type, count(*) as total')
            ->groupBy('model', 'type')
            ->get();

        $counted = [];

        foreach ($rows as $row) {
            $variant = (string) $row->model;
            $counted[$variant] ??= ['exposures' => 0, 'clicks' => 0];

            if ($row->type === 'exposure') {
                $counted[$variant]['exposures'] += (int) $row->total;
            }

            if ($row->type === 'click') {
                $counted[$variant]['clicks'] += (int) $row->total;
            }
        }

        return $counted;
    }

    /** @return array<string, int> */
    private function leadsByCta(): array
    {
        return Lead::query()
            ->whereNotNull('cta_id')
            ->where('created_at', '>=', now()->subDays(self::DAYS))
            ->selectRaw('cta_id, count(*) as total')
            ->groupBy('cta_id')
            ->pluck('total', 'cta_id')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * Whether the reviewer's scores told anybody anything.
     *
     * The plainest test there is: the lines it liked against the lines it merely allowed. If the
     * first are not clicked more often, its scores are decoration.
     *
     * @return array<string, mixed>
     */
    private function calibration(): array
    {
        $rows = LeadReview::query()
            ->whereNotNull('measured_at')
            ->where('exposures', '>', 0)
            ->get(['reviewer', 'score', 'exposures', 'clicks']);

        $liked = $rows->where('score', '>=', 85);
        $allowed = $rows->where('score', '<', 85);

        $rate = fn ($group): ?float => $group->sum('exposures') < self::ENOUGH
            ? null
            : round($group->sum('clicks') / max(1, $group->sum('exposures')), 4);

        $high = $rate($liked);
        $low = $rate($allowed);

        return [
            'reviewer' => $rows->first()?->reviewer,
            'liked' => ['lines' => $liked->count(), 'exposures' => (int) $liked->sum('exposures'), 'rate' => $high],
            'allowed' => ['lines' => $allowed->count(), 'exposures' => (int) $allowed->sum('exposures'), 'rate' => $low],
            'verdict' => match (true) {
                $high === null || $low === null => 'too_early',
                $high > $low * 1.1 => 'predicts',
                $high < $low * 0.9 => 'backwards',
                default => 'no_better_than_chance',
            },
        ];
    }

    /**
     * The version of a page's offer worth showing next, decided by readers rather than by anyone's
     * opinion of the wording.
     *
     * A version nobody has seen enough of is given its turn regardless: a page that always shows
     * the same line learns nothing about the others.
     *
     * @return array<string, float> variant => clicks per showing
     */
    public static function rates(): array
    {
        $rows = DB::table('analytics_events')
            ->where('candidate_id', 'cta')
            ->where('preview', false)
            ->where('holdout', false)
            ->where('occurred_at', '>=', now()->subDays(self::DAYS))
            ->selectRaw("model, sum(case when type = 'exposure' then 1 else 0 end) as seen, sum(case when type = 'click' then 1 else 0 end) as clicked")
            ->groupBy('model')
            ->get();

        $rates = [];

        foreach ($rows as $row) {
            if ((int) $row->seen >= self::ENOUGH) {
                $rates[(string) $row->model] = round((int) $row->clicked / (int) $row->seen, 4);
            }
        }

        return $rates;
    }
}
