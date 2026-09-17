<?php

namespace App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentVocabularies\Pages;

use App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentVocabularies\EnrichmentVocabularyResource;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use Filament\Resources\Pages\ViewRecord;

final class ViewEnrichmentVocabulary extends ViewRecord
{
    protected static string $resource = EnrichmentVocabularyResource::class;

    public function getTitle(): string
    {
        /** @var EnrichmentVocabulary $vocabulary */
        $vocabulary = $this->getRecord();

        return $vocabulary->title();
    }
}
