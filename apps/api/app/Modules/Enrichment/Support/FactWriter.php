<?php

namespace App\Modules\Enrichment\Support;

use App\Core\Facades\Features;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;
use App\Modules\Enrichment\Models\EnrichmentFact;

/**
 * Saves facts from one answer. With enrichment.auto_approve off for the shop, nothing is
 * approved without a person: what checks would approve waits in the person queue instead.
 */
final class FactWriter
{
    /**
     * Earlier facts about the same subject that no person decided are replaced by a new reading.
     */
    public function supersedePrevious(string $column, string $subjectId, array $kinds): void
    {
        EnrichmentFact::query()
            ->where($column, $subjectId)
            ->whereIn('kind', array_map(fn (FactKind $k): string => $k->value, $kinds))
            ->where('status', '!=', FactStatus::Superseded)
            ->whereNull('decided_by')
            ->update(['status' => FactStatus::Superseded, 'updated_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $values  kind, key, value_number, value_text, unit, quote, origin, status, status_reason
     */
    public function write(EnrichmentBatch $batch, EnrichmentBatchItem $item, string $column, string $model, array $values): EnrichmentFact
    {
        $status = $values['status'];

        if ($status === FactStatus::Approved && ! Features::enabled('enrichment.auto_approve', $batch->shop_id)) {
            $status = FactStatus::NeedsPerson;
        }

        return EnrichmentFact::query()->create([
            'shop_id' => $batch->shop_id,
            $column => $item->subject_id,
            'vocabulary_id' => $batch->vocabulary_id,
            'kind' => $values['kind'],
            'key' => $values['key'],
            'value_number' => $values['value_number'] ?? null,
            'value_text' => $values['value_text'] ?? null,
            'unit' => $values['unit'] ?? null,
            'quote' => isset($values['quote']) ? mb_substr((string) $values['quote'], 0, 500) : null,
            'origin' => $values['origin'] ?? FactOrigin::CodeAndModel,
            'status' => $status,
            'status_reason' => $values['status_reason'] ?? null,
            'batch_id' => $batch->id,
            'input_hash' => $item->input_hash,
            'model' => mb_substr($model, 0, 120),
        ]);
    }
}
