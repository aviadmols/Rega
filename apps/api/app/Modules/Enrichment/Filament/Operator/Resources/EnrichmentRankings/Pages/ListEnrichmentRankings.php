<?php

namespace App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentRankings\Pages;

use App\Modules\Enrichment\Actions\ComputeRankings;
use App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentRankings\EnrichmentRankingResource;
use App\Modules\Tenancy\Models\Shop;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListEnrichmentRankings extends ListRecords
{
    protected static string $resource = EnrichmentRankingResource::class;

    public function getSubheading(): ?string
    {
        return __('enrichment::ui.rankings.subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('compute')
                ->label(__('enrichment::ui.actions.compute_rankings'))
                ->icon(Heroicon::OutlinedCalculator)
                ->schema([
                    Select::make('shop_id')
                        ->label(__('enrichment::ui.fields.shop'))
                        ->options(fn (): array => Shop::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $run = app(ComputeRankings::class)->handle((string) $data['shop_id']);

                    Notification::make()->title((string) $run->summary())->success()->send();
                }),
        ];
    }
}
