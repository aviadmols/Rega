<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Scanning\ArticleReader;
use App\Modules\Enrichment\Support\ContentRules;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Facades\DB;

/**
 * Reads every article the store shares, on the article's own terms.
 *
 * A shop's guide was only ever read for the products it could sell. A site whose articles are
 * the product needs the other reading: what the piece is made of, what it concludes, what
 * question it answers, how long it takes to read. All of it in code, from the marker words in
 * the ruleset, so it costs nothing and gives the same answer twice.
 *
 * What it writes is a takeaway per point — the same kind of fact a product's highlights are, so
 * the widget can show them without learning anything new — plus the reading time and who the
 * piece is for. Every one carries the line it was read from.
 */
final class ReadContentInCode
{
    public const AGENT = 'enrichment.content_reader';

    public const ACTION = 'enrichment.read_content';

    /** Past this an article is a book, and the tail of it is not what a reader is deciding on. */
    private const MAX_CHARS = 40000;

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
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->read($run, $shopId)),
        );
    }

    private function read(RunContext $run, string $shopId): void
    {
        $status = Features::enabled('enrichment.auto_approve', $shopId) ? FactStatus::Approved : FactStatus::NeedsPerson;
        $rules = ContentRules::defaults();
        $counts = ['articles' => 0, 'takeaways' => 0, 'with_question' => 0, 'with_audience' => 0, 'empty' => 0];
        $samples = [];

        DB::transaction(function () use ($shopId, $status, $rules, &$counts, &$samples): void {
            // Code's earlier reading of articles, unless a person decided on it.
            EnrichmentFact::query()
                ->whereNotNull('content_id')
                ->whereIn('kind', [FactKind::Highlight, FactKind::Spec, FactKind::Tag])
                ->where('origin', FactOrigin::Code)
                ->where('status', '!=', FactStatus::Superseded)
                ->whereNull('decided_by')
                ->update(['status' => FactStatus::Superseded, 'updated_at' => now()]);

            CatalogContent::query()->active()->orderBy('external_id')
                ->each(function (CatalogContent $article) use ($shopId, $status, $rules, &$counts, &$samples): void {
                    $counts['articles']++;

                    $reading = ArticleReader::read(
                        (string) $article->title,
                        mb_substr((string) ($article->body ?? $article->excerpt ?? ''), 0, self::MAX_CHARS),
                        $rules,
                    );

                    $write = function (FactKind $kind, string $key, array $values) use ($shopId, $article, $status): void {
                        EnrichmentFact::query()->create($values + [
                            'shop_id' => $shopId,
                            'content_id' => $article->id,
                            'kind' => $kind,
                            'key' => $key,
                            'origin' => FactOrigin::Code,
                            'status' => $status,
                            'status_reason' => 'code_article',
                            'input_hash' => hash('sha256', $article->hash.'|'.$key),
                            'model' => 'code',
                        ]);
                    };

                    foreach ($reading['takeaways'] as $i => $takeaway) {
                        $counts['takeaways']++;
                        $write(FactKind::Highlight, 'takeaway_'.($i + 1), [
                            'value_text' => $takeaway['text'],
                            'value_number' => $i + 1,
                            'quote' => $takeaway['quote'],
                        ]);
                    }

                    if ($reading['question'] !== null) {
                        $counts['with_question']++;
                        $write(FactKind::Tag, 'answers', ['value_text' => $reading['question'], 'quote' => $reading['question']]);
                    }

                    if ($reading['audience'] !== null) {
                        $counts['with_audience']++;
                        $write(FactKind::Tag, 'audience', ['value_text' => $reading['audience'], 'quote' => $reading['audience']]);
                    }

                    $write(FactKind::Spec, 'reading_minutes', [
                        'value_number' => $reading['minutes'],
                        'unit' => 'min',
                        'quote' => (string) $reading['words'],
                    ]);

                    // An article code found nothing in is the interesting case, so it is counted
                    // and sampled: that is what the audit will be asked to look at first.
                    if ($reading['takeaways'] === [] && $reading['sections'] === []) {
                        $counts['empty']++;

                        if (count($samples) < 10) {
                            $samples[] = ['article' => $article->external_id, 'title' => mb_substr((string) $article->title, 0, 80), 'words' => $reading['words']];
                        }
                    }
                });
        });

        $run->output($counts + ['rules_version' => $rules['version'], 'found_nothing' => $samples])
            ->summary('enrichment::runs.read_content', [
                'articles' => number_format($counts['articles']),
                'takeaways' => number_format($counts['takeaways']),
                'empty' => number_format($counts['empty']),
            ]);
    }
}
