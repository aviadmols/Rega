<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Contracts\RereadsPages;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentContentRules;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Scanning\ArticleReader;
use App\Modules\Enrichment\Scanning\ProductDigest;
use App\Modules\Enrichment\Scanning\PromiseScanner;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use Illuminate\Support\Facades\DB;

/**
 * The code readers, for one page, on demand.
 *
 * A guide gets the article reader: its takeaways, the question it answers, who it is for, how
 * long it takes. A product gets the promise scanner: hand work, a material, where it was made.
 * The readings that need the whole shop to mean anything — superlatives, relations, the model's
 * own facts — are left alone, because re-reading one page cannot recompute a ranking against a
 * thousand others, and pretending otherwise would show a person a page that does not exist.
 *
 * What it writes supersedes code's earlier writing for that page, and only that page, and never a
 * fact a person decided on. It is logged like any run, so a rescan is never a silent change.
 */
final class RereadPage implements RereadsPages
{
    public const AGENT = 'enrichment.rereader';

    public const ACTION = 'enrichment.reread_page';

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
    ) {}

    public function reread(string $shopId, string $type, string $externalId): array
    {
        $result = ['written' => 0, 'kinds' => [], 'notes' => []];

        $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            input: ['type' => $type, 'id' => $externalId],
            work: function (RunContext $run) use ($shopId, $type, $externalId, &$result): void {
                $result = $this->tenant->run($shopId, fn (): array => $type === 'content'
                    ? $this->rereadArticle($shopId, $externalId)
                    : $this->rereadProduct($shopId, $externalId));

                $run->output($result)->summary('enrichment::runs.reread_page', [
                    'written' => $result['written'],
                ]);
            },
        );

        return $result;
    }

    /** @return array{written: int, kinds: array<string, int>, notes: list<string>} */
    private function rereadArticle(string $shopId, string $externalId): array
    {
        $article = CatalogContent::query()->active()->where('external_id', $externalId)->first();

        if ($article === null) {
            return ['written' => 0, 'kinds' => [], 'notes' => ['not_found']];
        }

        $rules = EnrichmentContentRules::inForce($shopId);
        $reading = ArticleReader::read((string) $article->title, (string) ($article->body ?? $article->excerpt ?? ''), $rules);
        $status = $this->status($shopId);
        $kinds = [];

        DB::transaction(function () use ($shopId, $article, $reading, $status, &$kinds): void {
            $this->supersede('content_id', $article->id, [FactKind::Highlight, FactKind::Spec, FactKind::Tag]);

            $write = function (FactKind $kind, string $key, array $values) use ($shopId, $article, $status, &$kinds): void {
                EnrichmentFact::query()->create($values + [
                    'shop_id' => $shopId, 'content_id' => $article->id, 'kind' => $kind, 'key' => $key,
                    'origin' => FactOrigin::Code, 'status' => $status, 'status_reason' => 'code_article',
                    'input_hash' => hash('sha256', $article->hash.'|'.$key), 'model' => 'code',
                ]);
                $kinds[$kind->value] = ($kinds[$kind->value] ?? 0) + 1;
            };

            foreach ($reading['takeaways'] as $i => $takeaway) {
                $write(FactKind::Highlight, 'takeaway_'.($i + 1), ['value_text' => $takeaway['text'], 'value_number' => $i + 1, 'quote' => $takeaway['quote']]);
            }
            if ($reading['question'] !== null) {
                $write(FactKind::Tag, 'answers', ['value_text' => $reading['question'], 'quote' => $reading['question']]);
            }
            if ($reading['audience'] !== null) {
                $write(FactKind::Tag, 'audience', ['value_text' => $reading['audience'], 'quote' => $reading['audience']]);
            }
            $write(FactKind::Spec, 'reading_minutes', ['value_number' => $reading['minutes'], 'unit' => 'min', 'quote' => (string) $reading['words']]);
        });

        return [
            'written' => array_sum($kinds),
            'kinds' => $kinds,
            'notes' => $reading['takeaways'] === [] ? ['no_takeaways'] : [],
        ];
    }

    /** @return array{written: int, kinds: array<string, int>, notes: list<string>} */
    private function rereadProduct(string $shopId, string $externalId): array
    {
        $product = CatalogProduct::query()->active()->where('external_id', $externalId)->first();

        if ($product === null) {
            return ['written' => 0, 'kinds' => [], 'notes' => ['not_found']];
        }

        $text = trim(implode("\n", array_filter(ProductDigest::sections($product))));
        $promises = PromiseScanner::product($text);
        $status = $this->status($shopId);

        DB::transaction(function () use ($shopId, $product, $promises, $status): void {
            $this->supersede('product_id', $product->id, [FactKind::Promise]);

            foreach ($promises as $promise) {
                EnrichmentFact::query()->create([
                    'shop_id' => $shopId, 'product_id' => $product->id, 'kind' => FactKind::Promise,
                    'key' => $promise['key'], 'value_text' => $promise['detail'], 'quote' => $promise['quote'],
                    'origin' => FactOrigin::Code, 'status' => $status, 'status_reason' => 'code_product_text',
                    'input_hash' => hash('sha256', $product->hash.'|'.$promise['key']), 'model' => 'code',
                ]);
            }
        });

        return [
            'written' => count($promises),
            'kinds' => $promises === [] ? [] : [FactKind::Promise->value => count($promises)],
            // What a single page cannot recompute on its own, said out loud.
            'notes' => ['rankings_and_relations_need_the_whole_shop'],
        ];
    }

    /** @param list<FactKind> $kinds */
    private function supersede(string $column, string $id, array $kinds): void
    {
        EnrichmentFact::query()
            ->where($column, $id)
            ->whereIn('kind', $kinds)
            ->where('origin', FactOrigin::Code)
            ->where('status', '!=', FactStatus::Superseded)
            ->whereNull('decided_by')
            ->update(['status' => FactStatus::Superseded, 'updated_at' => now()]);
    }

    private function status(string $shopId): FactStatus
    {
        return Features::enabled('enrichment.auto_approve', $shopId) ? FactStatus::Approved : FactStatus::NeedsPerson;
    }
}
