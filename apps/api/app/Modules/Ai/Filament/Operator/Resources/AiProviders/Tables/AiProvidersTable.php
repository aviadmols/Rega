<?php

namespace App\Modules\Ai\Filament\Operator\Resources\AiProviders\Tables;

use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Ai\Enums\ProviderStatus;
use App\Modules\Ai\Models\AiProvider;
use App\Modules\Ai\Support\ProviderTestNotifier;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class AiProvidersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')
                    ->label(__('ai::providers.fields.provider'))
                    ->formatStateUsing(fn (AiProviderName $state): string => $state->label())
                    ->description(fn (AiProvider $record): string => $record->provider->role()),
                TextColumn::make('status')
                    ->label(__('ai::providers.fields.status'))
                    ->badge()
                    ->color(fn (ProviderStatus $state): string => $state->color())
                    ->formatStateUsing(fn (ProviderStatus $state): string => $state->label()),
                TextColumn::make('key_hint')
                    ->label(__('ai::providers.fields.key_hint'))
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('model_count')
                    ->label(__('ai::providers.fields.model_count'))
                    ->state(fn (AiProvider $record): ?int => $record->models === null ? null : count($record->models))
                    ->placeholder('-'),
                TextColumn::make('last_checked_at')
                    ->label(__('ai::providers.fields.last_checked_at'))
                    ->since()
                    ->placeholder(__('ai::providers.fields.never')),
            ])
            ->recordActions([
                Action::make('test')
                    ->label(__('ai::providers.actions.test'))
                    ->icon(Heroicon::OutlinedSignal)
                    ->action(fn (AiProvider $record) => ProviderTestNotifier::test($record)),
                EditAction::make(),
            ])
            ->emptyStateHeading(__('ai::providers.empty.heading'))
            ->emptyStateDescription(__('ai::providers.empty.description'));
    }
}
