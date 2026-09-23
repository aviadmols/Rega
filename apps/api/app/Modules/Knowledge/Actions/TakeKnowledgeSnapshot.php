<?php

namespace App\Modules\Knowledge\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Analytics\Models\AnalyticsPopularity;
use App\Modules\Analytics\Models\AnalyticsScore;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentProductRelation;
use App\Modules\Enrichment\Models\EnrichmentRanking;
use App\Modules\Knowledge\Models\KnowledgeSnapshot;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\DB;

/**
 * Everything known about a shop today, written down so tomorrow can be compared to it.
 *
 * The rest of the system holds only the present tense: these are the facts, this is the order the
 * panels are in. Nothing anywhere can answer "what did the system learn this week", because
 * nothing keeps yesterday. This does — one row a night per shop, counts only.
 *
 * It is also the one place that measures what is *missing*, which is the more useful half. A shop
 * with two hundred products and no facts about any of them looks fine in every existing screen.
 */
final class TakeKnowledgeSnapshot
{
    public const AGENT = 'knowledge.recorder';

    public const ACTION = 'knowledge.snapshot';

    /** The window the reward signals are counted over. */
    private const SIGNAL_DAYS = 30;

    /** A signal seen fewer times than this over the window cannot be learned from. */
    private const SIGNAL_ALIVE = 30;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly InferVertical $vertical,
    ) {}

    public function handle(Shop $shop): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shop->id,
            work: function (RunContext $run) use ($shop): void {
                $this->vertical->handle($shop);

                $snapshot = $this->tenant->run($shop->id, fn (): KnowledgeSnapshot => $this->write($shop));

                $run->output([
                    'vertical' => $shop->fresh()?->vertical,
                    'coverage' => $snapshot->coverage,
                    'gaps' => count($snapshot->gaps),
                ])->summary('knowledge::runs.snapshot', [
                    'products' => number_format((int) ($snapshot->coverage['catalog']['products'] ?? 0)),
                    'known' => (string) ($snapshot->coverage['known_share'] ?? 0),
                    'gaps' => (string) count($snapshot->gaps),
                ]);
            },
        );
    }

    private function write(Shop $shop): KnowledgeSnapshot
    {
        $coverage = $this->coverage();
        $gaps = $this->gaps($coverage);

        return KnowledgeSnapshot::query()->updateOrCreate(
            ['shop_id' => $shop->id, 'taken_on' => today()],
            [
                'coverage' => $coverage,
                'freshness' => $this->freshness($shop->id),
                'gaps' => $gaps,
                'arrangement' => $this->arrangement(),
                'signals' => $this->signals($shop->id),
            ],
        );
    }

    /**
     * How much of what was scanned the system has something to say about.
     *
     * @return array<string, mixed>
     */
    private function coverage(): array
    {
        $products = CatalogProduct::query()->whereNull('removed_at')->count();
        $articles = CatalogContent::query()->whereNull('removed_at')->where('type', '!=', 'page')->count();
        $pages = CatalogContent::query()->whereNull('removed_at')->where('type', 'page')->count();

        $withCode = $this->productsWith(FactOrigin::Code);
        $withModel = $this->productsWith(FactOrigin::Model);
        $decided = EnrichmentFact::query()->whereNotNull('decided_by')->count();

        $byKind = EnrichmentFact::query()
            ->where('status', FactStatus::Approved)
            ->whereNotNull('product_id')
            ->select('kind', DB::raw('count(distinct product_id) as products'))
            ->groupBy('kind')
            ->pluck('products', 'kind')
            ->all();

        $takeaways = EnrichmentFact::query()
            ->whereNotNull('content_id')
            ->where('kind', FactKind::Highlight)
            ->where('status', FactStatus::Approved)
            ->distinct()
            ->count('content_id');

        return [
            'catalog' => ['products' => $products, 'articles' => $articles, 'pages' => $pages],
            'read_in_code' => ['products' => $withCode, 'share' => self::share($withCode, $products)],
            'read_by_model' => ['products' => $withModel, 'share' => self::share($withModel, $products)],
            'decided_by_people' => ['facts' => $decided],
            'by_kind' => array_map('intval', $byKind),
            'articles_with_points' => ['articles' => $takeaways, 'share' => self::share($takeaways, $articles)],
            'computed' => [
                'rankings' => EnrichmentRanking::query()->count(),
                'relations' => EnrichmentProductRelation::query()->count(),
                'popular' => AnalyticsPopularity::query()->where('popular', true)->count(),
            ],
            'questions' => [
                'answered' => AssistantAnswer::query()->where('outcome', AssistantAnswer::ANSWERED)->count(),
                'no_info' => AssistantAnswer::query()->where('outcome', AssistantAnswer::NO_INFO)->count(),
                'refused' => AssistantAnswer::query()->where('outcome', AssistantAnswer::OUT_OF_SCOPE)->count(),
            ],
            // One number for the top of the screen: the share of products the system can say
            // anything checked about at all.
            'known_share' => self::share(max($withCode, $withModel), $products),
        ];
    }

    private function productsWith(FactOrigin $origin): int
    {
        return EnrichmentFact::query()
            ->whereNotNull('product_id')
            ->where('origin', $origin)
            ->where('status', FactStatus::Approved)
            ->distinct()
            ->count('product_id');
    }

    /**
     * When each step last finished, so an old reading can be told from a fresh one.
     *
     * @return array<string, ?string>
     */
    private function freshness(string $shopId): array
    {
        $actions = [
            'catalog.sync', 'enrichment.read_in_code', 'enrichment.read_promises',
            'enrichment.read_content', 'enrichment.compute_rankings', 'enrichment.compute_relations',
            'enrichment.audit_content', 'analytics.compute_scores', 'analytics.compute_popularity',
        ];

        $last = $this->tenant->runUnscoped(fn () => Run::query()
            ->where('shop_id', $shopId)
            ->whereIn('action', $actions)
            ->where('status', 'succeeded')
            ->select('action', DB::raw('max(finished_at) as at'))
            ->groupBy('action')
            ->pluck('at', 'action')
            ->all());

        $freshness = [];

        foreach ($actions as $action) {
            $at = $last[$action] ?? null;
            $freshness[$action] = $at === null ? null : (string) $at;
        }

        return $freshness;
    }

    /**
     * What is missing, each with the step that would close it.
     *
     * A gap is not a failure; it is the most useful thing on the screen, because it is the only
     * part that tells a shop what to do next.
     *
     * @param  array<string, mixed>  $coverage
     * @return list<array{key: string, count: int, fix: ?string}>
     */
    private function gaps(array $coverage): array
    {
        $gaps = [];

        $withAnything = EnrichmentFact::query()
            ->whereNotNull('product_id')
            ->where('status', FactStatus::Approved)
            ->distinct()
            ->count('product_id');
        $noFacts = max(0, (int) $coverage['catalog']['products'] - $withAnything);

        if ($noFacts > 0) {
            $gaps[] = ['key' => 'products_without_facts', 'count' => $noFacts, 'fix' => 'enrichment.read_in_code'];
        }

        $articlesWithout = max(0, (int) $coverage['catalog']['articles'] - (int) $coverage['articles_with_points']['articles']);

        if ($articlesWithout > 0) {
            $gaps[] = ['key' => 'articles_without_points', 'count' => $articlesWithout, 'fix' => 'enrichment.audit_content'];
        }

        // A shop that shares no pages cannot have shop-level promises: the returns and shipping
        // pages are where they are written.
        if ((int) $coverage['catalog']['pages'] === 0) {
            $gaps[] = ['key' => 'no_pages_shared', 'count' => 0, 'fix' => null];
        }

        $noInfo = (int) $coverage['questions']['no_info'];

        if ($noInfo > 0) {
            // Nothing automatic can close this one: the shop never wrote the answer down.
            $gaps[] = ['key' => 'questions_without_an_answer', 'count' => $noInfo, 'fix' => null];
        }

        if ((int) $coverage['computed']['relations'] === 0 && (int) $coverage['catalog']['products'] > 0) {
            $gaps[] = ['key' => 'no_relations', 'count' => 0, 'fix' => 'enrichment.compute_relations'];
        }

        return $gaps;
    }

    /**
     * What the shopper is shown, in order, per kind of page.
     *
     * @return array<string, list<string>>
     */
    private function arrangement(): array
    {
        $order = AnalyticsScore::query()
            ->where('scope', AnalyticsScore::SCOPE_MODULE)
            ->orderByDesc('score')
            ->pluck('candidate')
            ->all();

        return ['learned_order' => array_values(array_unique($order))];
    }

    /**
     * What the learning is being judged on, and whether each signal is alive enough to judge by.
     *
     * @return array<string, mixed>
     */
    private function signals(string $shopId): array
    {
        $since = now()->subDays(self::SIGNAL_DAYS);

        $counts = AnalyticsEvent::query()
            ->where('occurred_at', '>=', $since)
            ->select('type', DB::raw('count(*) as c'))
            ->groupBy('type')
            ->pluck('c', 'type')
            ->all();

        $orders = AnalyticsOrder::query()->where('ordered_at', '>=', $since)->count();

        $signals = [
            'exposure' => (int) ($counts['exposure'] ?? 0),
            'open' => (int) ($counts['open'] ?? 0),
            'click' => (int) ($counts['click'] ?? 0),
            'dismiss' => (int) ($counts['dismiss'] ?? 0),
            'add_to_cart' => (int) ($counts['add_to_cart'] ?? 0),
            'order' => $orders,
        ];

        $verdicts = [];

        foreach ($signals as $name => $count) {
            $verdicts[$name] = match (true) {
                $count === 0 => 'missing',
                $count < self::SIGNAL_ALIVE => 'weak',
                default => 'alive',
            };
        }

        // What the learning actually optimises for today: the strongest signal that is alive, in
        // the order of how much it is worth knowing.
        $best = 'none';

        foreach (['order', 'add_to_cart', 'click', 'open'] as $name) {
            if ($verdicts[$name] === 'alive') {
                $best = $name;

                break;
            }
        }

        return ['window_days' => self::SIGNAL_DAYS, 'counts' => $signals, 'verdicts' => $verdicts, 'optimising_for' => $best];
    }

    private static function share(int $part, int $whole): int
    {
        return $whole === 0 ? 0 : (int) round(100 * $part / $whole);
    }
}
