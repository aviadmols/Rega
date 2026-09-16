<?php

namespace App\Modules\Tenancy\Filament\Operator\Resources\Shops\Tables;

use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;

final class ShopsTable
{
    /**
     * The configuration screen belongs to the Admin module. Linking by route name keeps this
     * module free of any import from it.
     */
    private const CONFIGURATION_ROUTE = 'filament.operator.pages.configuration';

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'apiKeys as active_keys_count' => fn (Builder $keys) => $keys->whereNull('revoked_at'),
            ]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('tenancy::shops.fields.name'))
                    ->searchable(['name', 'domain'])
                    ->sortable()
                    ->description(fn (Shop $record): string => $record->domain),
                TextColumn::make('platform')
                    ->label(__('tenancy::shops.fields.platform'))
                    ->badge()
                    ->formatStateUsing(fn (ShopPlatform $state): string => $state->label()),
                TextColumn::make('status')
                    ->label(__('tenancy::shops.fields.status'))
                    ->badge()
                    ->color(fn (ShopStatus $state): string => $state->color())
                    ->formatStateUsing(fn (ShopStatus $state): string => $state->label()),
                TextColumn::make('active_keys_count')
                    ->label(__('tenancy::shops.fields.active_keys'))
                    ->numeric(),
                // Secondary columns start hidden so the row actions stay visible on a laptop.
                TextColumn::make('content_locale')
                    ->label(__('tenancy::shops.fields.content_locale'))
                    ->formatStateUsing(fn (string $state): string => __("tenancy::shops.locales.{$state}"))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(__('tenancy::shops.fields.created_at'))
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('tenancy::shops.fields.status'))
                    ->options(ShopStatus::options()),
                SelectFilter::make('platform')
                    ->label(__('tenancy::shops.fields.platform'))
                    ->options(ShopPlatform::options()),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('configure')
                    ->label(__('tenancy::shops.actions.configure'))
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->visible(fn (): bool => Route::has(self::CONFIGURATION_ROUTE))
                    ->url(fn (Shop $record): string => route(self::CONFIGURATION_ROUTE, ['shop' => $record->id])),
            ]);
    }
}
