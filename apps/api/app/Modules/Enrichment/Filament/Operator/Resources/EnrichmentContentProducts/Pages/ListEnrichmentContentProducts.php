<?php

namespace App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentContentProducts\Pages;

use App\Modules\Enrichment\Actions\MatchProductsToArticles;
use App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentContentProducts\EnrichmentContentProductResource;
use App\Modules\Tenancy\Models\Shop;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListEnrichmentContentProducts extends ListRecords
{
    protected static string $resource = EnrichmentContentProductResource::class;

    public function getSubheading(): ?string
    {
        return __('enrichment::ui.article_products.subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('match')
                ->label(__('enrichment::ui.actions.match_article_products'))
                ->icon(Heroicon::OutlinedLink)
                ->schema([
                    Select::make('shop_id')
                        ->label(__('enrichment::ui.fields.shop'))
                        ->options(fn (): array => Shop::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $run = app(MatchProductsToArticles::class)->handle((string) $data['shop_id']);

                    Notification::make()->title((string) $run->summary())->success()->send();
                }),
        ];
    }
}
