<?php

namespace App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentBatches\RelationManagers;

use App\Modules\Enrichment\Enums\ItemStatus;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('enrichment::ui.items.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('custom_id')->label(__('enrichment::ui.items.custom_id'))->fontFamily(FontFamily::Mono)->searchable(),
                TextColumn::make('request.title')->label(__('enrichment::ui.fields.subject'))->wrap()
                    ->state(fn (EnrichmentBatchItem $record): string => (string) ($record->request['title'] ?? $record->request['id'] ?? '')),
                TextColumn::make('status')->label(__('enrichment::ui.fields.status'))->badge()
                    ->formatStateUsing(fn (ItemStatus $state): string => __("enrichment::ui.item_statuses.{$state->value}"))
                    ->color(fn (ItemStatus $state): string => match ($state) {
                        ItemStatus::Applied => 'success',
                        ItemStatus::Pending => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('problems')->label(__('enrichment::ui.fields.problems'))
                    ->state(fn (EnrichmentBatchItem $record): string => implode(', ', (array) $record->problems))
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('-')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('enrichment::ui.fields.status'))
                    ->options(collect(ItemStatus::cases())->mapWithKeys(fn (ItemStatus $s): array => [$s->value => __("enrichment::ui.item_statuses.{$s->value}")])->all()),
            ]);
    }
}
