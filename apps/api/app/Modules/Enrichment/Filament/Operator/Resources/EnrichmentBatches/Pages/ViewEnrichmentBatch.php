<?php

namespace App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentBatches\Pages;

use App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentBatches\EnrichmentBatchResource;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Support\UploadResultsAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewEnrichmentBatch extends ViewRecord
{
    protected static string $resource = EnrichmentBatchResource::class;

    protected function getHeaderActions(): array
    {
        /** @var EnrichmentBatch $batch */
        $batch = $this->getRecord();

        return [
            Action::make('download')
                ->label(__('enrichment::ui.actions.download'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(route('enrichment.batches.download', ['batch' => $batch->id])),
            UploadResultsAction::make(),
        ];
    }
}
