<?php

namespace App\Modules\Enrichment\Tasks;

use App\Core\Facades\Settings;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\ItemStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Scanning\ContentDigest;
use App\Modules\Enrichment\Support\FactWriter;
use Illuminate\Support\Collection;

/**
 * Reads the store's articles: what each is, how much it helps a shopper, which categories.
 * A single model's reading, so every claim goes to the reviewer before anyone relies on it.
 */
final class ContentMappingTask implements AgentTask
{
    public const SUBJECT = 'content';

    public const KINDS = ['buying_guide', 'how_to', 'project_idea', 'material_guide', 'product_review', 'brand_story', 'store_page', 'news', 'other'];

    public const VALUES = ['high', 'medium', 'low', 'none'];

    /** @var Collection<int, CatalogCategory>|null */
    private ?Collection $categories = null;

    public function __construct(private readonly FactWriter $facts) {}

    public function type(): TaskType
    {
        return TaskType::ContentMapping;
    }

    public function requests(EnrichmentBatch $batch, int $limit): iterable
    {
        $maxChars = (int) Settings::get('enrichment.max_agent_text_chars', $batch->shop_id);
        $includeDone = (bool) ($batch->scope['include_done'] ?? false);
        $count = 0;

        foreach (CatalogContent::query()->whereNull('removed_at')->lazyById(100) as $content) {
            $digest = ContentDigest::build($content, $this->categories(), $maxChars, $batch->prompt_hash);

            if (! $includeDone && $this->alreadyRead($content->id, $digest->inputHash)) {
                continue;
            }

            yield new TaskRequest(
                customId: 'cm-'.$content->external_id.'-'.substr($digest->inputHash, 0, 10),
                subjectType: self::SUBJECT,
                subjectId: $content->id,
                inputHash: $digest->inputHash,
                request: $digest->request,
                context: $digest->context,
            );

            if (++$count >= $limit) {
                return;
            }
        }
    }

    public function isStale(EnrichmentBatch $batch, EnrichmentBatchItem $item): bool
    {
        $content = CatalogContent::query()->find($item->subject_id);

        if ($content === null || $content->removed_at !== null) {
            return true;
        }

        $maxChars = (int) Settings::get('enrichment.max_agent_text_chars', $batch->shop_id);

        return ContentDigest::build($content, $this->categories(), $maxChars, $batch->prompt_hash)->inputHash !== $item->input_hash;
    }

    public function apply(EnrichmentBatch $batch, EnrichmentBatchItem $item, array $output, string $model): ItemOutcome
    {
        if ((string) ($output['id'] ?? '') !== (string) $item->request['id']) {
            return ItemOutcome::rejected(['wrong_id']);
        }

        $kind = $output['kind'] ?? null;
        $value = $output['value'] ?? null;

        if (! in_array($kind, self::KINDS, true) || ! in_array($value, self::VALUES, true)) {
            return ItemOutcome::rejected(['invalid_kind_or_value']);
        }

        $problems = [];
        $saved = 0;

        $this->facts->supersedePrevious('content_id', $item->subject_id, [FactKind::ContentKind, FactKind::ShopperValue, FactKind::Category, FactKind::Use]);

        foreach ([[FactKind::ContentKind, 'content_kind', $kind], [FactKind::ShopperValue, 'shopper_value', $value]] as [$factKind, $key, $text]) {
            $this->facts->write($batch, $item, 'content_id', $model, [
                'kind' => $factKind,
                'key' => $key,
                'value_text' => $text,
                'quote' => $item->request['title'] ?? null,
                'origin' => FactOrigin::Model,
                'status' => FactStatus::AwaitingReview,
                'status_reason' => 'model_only',
            ]);
            $saved++;
        }

        $map = (array) ($item->context['categories'] ?? []);

        foreach (array_unique(array_filter(is_array($output['categories'] ?? null) ? $output['categories'] : [], 'is_string')) as $id) {
            if (! isset($map[$id])) {
                $problems[] = "unknown_category:{$id}";

                continue;
            }

            // A category found by code and confirmed by the model.
            $this->facts->write($batch, $item, 'content_id', $model, [
                'kind' => FactKind::Category,
                'key' => 'category',
                'value_text' => (string) $map[$id]['external_id'],
                'quote' => (string) $map[$id]['path'],
                'origin' => FactOrigin::CodeAndModel,
                'status' => FactStatus::AwaitingReview,
                'status_reason' => 'model_only',
            ]);
            $saved++;
        }

        $allowedUses = (array) ($batch->scope['uses'] ?? []);

        foreach (array_slice(array_values(array_unique(array_filter(is_array($output['uses'] ?? null) ? $output['uses'] : [], 'is_string'))), 0, 4) as $use) {
            if (! in_array($use, $allowedUses, true)) {
                $problems[] = "unknown_use:{$use}";

                continue;
            }

            $this->facts->write($batch, $item, 'content_id', $model, [
                'kind' => FactKind::Use,
                'key' => 'use',
                'value_text' => $use,
                'quote' => $item->request['title'] ?? null,
                'origin' => FactOrigin::Model,
                'status' => FactStatus::AwaitingReview,
                'status_reason' => 'use',
            ]);
            $saved++;
        }

        return new ItemOutcome(ItemStatus::Applied, $saved, count($problems), $problems);
    }

    /** @return Collection<int, CatalogCategory> */
    private function categories(): Collection
    {
        return $this->categories ??= CatalogCategory::query()->whereNull('removed_at')->get();
    }

    private function alreadyRead(string $contentId, string $inputHash): bool
    {
        return EnrichmentFact::query()->where('content_id', $contentId)->where('input_hash', $inputHash)->where('status', '!=', FactStatus::Superseded)->exists()
            || EnrichmentBatchItem::query()->where('subject_id', $contentId)->where('input_hash', $inputHash)->whereIn('status', [ItemStatus::Pending, ItemStatus::Applied])->exists();
    }
}
