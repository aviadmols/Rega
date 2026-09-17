<?php

namespace App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentFacts\Pages;

use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentFacts\EnrichmentFactResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListEnrichmentFacts extends ListRecords
{
    protected static string $resource = EnrichmentFactResource::class;

    public function getSubheading(): ?string
    {
        return __('enrichment::ui.facts.subheading');
    }

    public function getTabs(): array
    {
        return [
            'person' => Tab::make(__('enrichment::ui.facts.tabs.person'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', FactStatus::NeedsPerson)),
            'checking' => Tab::make(__('enrichment::ui.facts.tabs.checking'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [FactStatus::AwaitingReview, FactStatus::AwaitingEscalation])),
            'approved' => Tab::make(__('enrichment::ui.facts.tabs.approved'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', FactStatus::Approved)),
            'rejected' => Tab::make(__('enrichment::ui.facts.tabs.rejected'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', FactStatus::Rejected)),
            'all' => Tab::make(__('enrichment::ui.facts.tabs.all')),
        ];
    }
}
