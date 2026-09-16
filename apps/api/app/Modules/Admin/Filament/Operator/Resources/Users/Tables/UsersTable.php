<?php

namespace App\Modules\Admin\Filament\Operator\Resources\Users\Tables;

use App\Modules\Admin\Models\User;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin::users.fields.name'))
                    ->searchable(['name', 'email'])
                    ->sortable()
                    ->description(fn (User $record): string => $record->email),
                IconColumn::make('is_operator')
                    ->label(__('admin::users.fields.is_operator'))
                    ->boolean(),
                TextColumn::make('shops.name')
                    ->label(__('admin::users.fields.shops'))
                    ->badge()
                    ->limitList(3),
                TextColumn::make('locale')
                    ->label(__('admin::users.fields.locale'))
                    ->formatStateUsing(fn (?string $state): string => $state === null ? __('admin::users.fields.locale_auto') : __("admin::panels.locale.names.{$state}"))
                    ->placeholder(__('admin::users.fields.locale_auto')),
            ])
            ->filters([
                TernaryFilter::make('is_operator')
                    ->label(__('admin::users.fields.is_operator')),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
