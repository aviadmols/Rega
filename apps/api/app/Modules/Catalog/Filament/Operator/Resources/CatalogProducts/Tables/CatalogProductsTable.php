<?php

namespace App\Modules\Catalog\Filament\Operator\Resources\CatalogProducts\Tables;

use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class CatalogProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('shop'))
            ->defaultSort('source_updated_at', 'desc')
            ->columns([
                ImageColumn::make('image_url')
                    ->label(__('catalog::catalog.fields.image'))
                    ->square()
                    ->imageSize(44),
                TextColumn::make('title')
                    ->label(__('catalog::catalog.fields.title'))
                    ->searchable(['title', 'sku', 'external_id'])
                    ->wrap()
                    ->description(fn (CatalogProduct $record): string => implode(' · ', array_filter([$record->brand, $record->sku]))),
                TextColumn::make('shop.name')
                    ->label(__('catalog::catalog.fields.shop'))
                    ->toggleable(),
                TextColumn::make('price')
                    ->label(__('catalog::catalog.fields.price'))
                    ->money(fn (CatalogProduct $record): string => $record->currency ?? 'ILS')
                    ->sortable(),
                IconColumn::make('in_stock')
                    ->label(__('catalog::catalog.fields.in_stock'))
                    ->boolean(),
                TextColumn::make('variations_count')
                    ->label(__('catalog::catalog.fields.variations'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source_updated_at')
                    ->label(__('catalog::catalog.fields.updated_in_store'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
                TextColumn::make('removed_at')
                    ->label(__('catalog::catalog.fields.removed_at'))
                    ->since()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('shop')
                    ->label(__('catalog::catalog.fields.shop'))
                    ->relationship('shop', 'name')
                    ->preload(),
                SelectFilter::make('category')
                    ->label(__('catalog::catalog.fields.category'))
                    ->searchable()
                    ->options(fn (): array => CatalogCategory::query()
                        ->whereNull('removed_at')
                        ->get()
                        ->sortBy(fn (CatalogCategory $c): string => $c->pathLabel())
                        ->mapWithKeys(fn (CatalogCategory $c): array => [$c->id => $c->pathLabel()])
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereHas('categories', fn (Builder $categories) => $categories->whereKey($data['value']))),
                TernaryFilter::make('in_stock')
                    ->label(__('catalog::catalog.fields.in_stock')),
                TernaryFilter::make('removed')
                    ->label(__('catalog::catalog.fields.removed'))
                    ->default(false)
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('removed_at'),
                        false: fn (Builder $query) => $query->whereNull('removed_at'),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading(__('catalog::catalog.empty.heading'))
            ->emptyStateDescription(__('catalog::catalog.empty.description'));
    }
}
