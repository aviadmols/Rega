<?php

namespace App\Modules\Leads\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Leads\Models\LeadCta;
use App\Modules\Leads\Models\LeadFlow;
use App\Modules\Leads\Support\CtaComposer;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Facades\DB;

/**
 * A call to action for every page, written from what the scan found in it.
 *
 * The material is already there and has been for weeks: the subject an article keeps returning
 * to, the question it answers, who it is for, how many points it makes. All of it was read for
 * the widget's panels, and it is exactly what a banner needs to stop being the same banner on
 * every page.
 *
 * Nothing here calls a model. It runs in the nightly reading, costs nothing, and gives every page
 * something honest before a model has been asked — which also means a model that turns out to
 * write badly costs a night rather than the whole site.
 */
final class ComposeCallsToAction
{
    public const AGENT = 'leads.composer';

    public const ACTION = 'leads.compose_ctas';

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
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->compose($run, $shopId)),
        );
    }

    private function compose(RunContext $run, string $shopId): void
    {
        $flow = LeadFlow::inForce($shopId);

        if ($flow === null || ! $flow->canReachAnyone()) {
            $run->output(['pages' => 0])->summary('leads::runs.no_flow');

            return;
        }

        $rules = $flow->rules();
        $counts = ['pages' => 0, 'written' => 0, 'refused' => 0];

        DB::transaction(function () use ($shopId, $flow, $rules, &$counts): void {
            // Everything composed before is set aside rather than deleted: a click recorded
            // against a version has to keep meaning something.
            LeadCta::query()->where('source', 'code')->update(['active' => false]);

            foreach ($this->pages() as $page) {
                $versions = CtaComposer::compose($flow->goal, $flow->offer, $page['about'], $rules, 'he');

                if ($versions === []) {
                    $counts['refused']++;

                    continue;
                }

                $counts['pages']++;

                foreach ($versions as $version) {
                    LeadCta::query()->updateOrCreate(
                        [
                            'shop_id' => $shopId,
                            'page_type' => $page['type'],
                            'page_external_id' => $page['external_id'],
                            'variant' => $version['key'],
                        ],
                        [
                            'headline' => $version['headline'],
                            'body' => $version['body'],
                            'source' => 'code',
                            'model' => null,
                            'active' => true,
                            'composed_at' => now(),
                        ],
                    );
                    $counts['written']++;
                }
            }
        });

        $run->output($counts)->summary('leads::runs.composed', [
            'pages' => number_format($counts['pages']),
            'written' => number_format($counts['written']),
        ]);
    }

    /**
     * Every page that could carry one, with what the scan knows about it.
     *
     * @return iterable<array{type: string, external_id: string, about: array<string, mixed>}>
     */
    private function pages(): iterable
    {
        foreach (CatalogContent::query()->active()->lazyById(100) as $article) {
            yield [
                'type' => 'content',
                'external_id' => (string) $article->external_id,
                'about' => $this->aboutArticle($article),
            ];
        }

        // What kind of thing each product is, as the scan read it. A title is a title —
        // "Bosch GSB 18V-55 cordless combi drill, two batteries" is not a subject a sentence can
        // hold, while "drill" is exactly one.
        $kinds = EnrichmentFact::query()
            ->whereNotNull('product_id')
            ->where('kind', FactKind::Type)
            ->where('status', FactStatus::Approved)
            ->pluck('value_text', 'product_id');

        foreach (CatalogProduct::query()->whereNull('removed_at')->lazyById(200) as $product) {
            yield [
                'type' => 'product',
                'external_id' => (string) $product->external_id,
                'about' => [
                    'subject' => $kinds[$product->id] ?? (string) $product->title,
                    'audience' => null,
                    'points' => 0,
                    'question' => null,
                ],
            ];
        }
    }

    /** @return array<string, mixed> */
    private function aboutArticle(CatalogContent $article): array
    {
        $facts = EnrichmentFact::query()
            ->where('content_id', $article->id)
            ->where('status', FactStatus::Approved)
            ->get(['kind', 'key', 'value_text', 'quote']);

        return [
            // The subject the scan decided the piece keeps returning to, when it found one.
            'subject' => $facts->first(fn (EnrichmentFact $f): bool => $f->kind === FactKind::Tag && $f->quote === 'term')?->value_text
                ?? (string) $article->title,
            'audience' => $facts->firstWhere('key', 'audience')?->value_text,
            'points' => $facts->where('kind', FactKind::Highlight)->count(),
            'question' => $facts->firstWhere('key', 'answers')?->value_text,
        ];
    }
}
