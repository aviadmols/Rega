<?php

namespace App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentBatches\Pages;

use App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentBatches\EnrichmentBatchResource;
use App\Modules\Enrichment\Support\CreateTaskFileAction;
use Filament\Resources\Pages\ListRecords;

final class ListEnrichmentBatches extends ListRecords
{
    protected static string $resource = EnrichmentBatchResource::class;

    public function getSubheading(): ?string
    {
        return __('enrichment::ui.batches.subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateTaskFileAction::make(),
        ];
    }
}
