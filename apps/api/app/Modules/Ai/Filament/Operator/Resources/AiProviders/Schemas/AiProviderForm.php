<?php

namespace App\Modules\Ai\Filament\Operator\Resources\AiProviders\Schemas;

use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Ai\Enums\ProviderStatus;
use App\Modules\Ai\Models\AiProvider;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Illuminate\Support\Facades\Lang;

final class AiProviderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('ai::providers.sections.key'))
                    ->description(__('ai::providers.sections.key_help'))
                    ->columns(2)
                    ->schema([
                        Select::make('provider')
                            ->label(__('ai::providers.fields.provider'))
                            ->options(fn (?AiProvider $record): array => self::providerOptions($record))
                            ->helperText(fn (?string $state): ?string => $state ? AiProviderName::from($state)->role() : null)
                            ->live()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->disabledOn('edit'),
                        TextInput::make('api_key')
                            ->label(__('ai::providers.fields.api_key'))
                            ->helperText(fn (string $operation): ?string => $operation === 'edit' ? __('ai::providers.fields.api_key_keep') : null)
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->extraInputAttributes(['dir' => 'ltr']),
                    ]),
                Section::make(__('ai::providers.sections.status'))
                    ->columns(4)
                    ->visibleOn('edit')
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('ai::providers.fields.status'))
                            ->badge()
                            ->color(fn (ProviderStatus $state): string => $state->color())
                            ->formatStateUsing(fn (ProviderStatus $state): string => $state->label()),
                        TextEntry::make('key_hint')
                            ->label(__('ai::providers.fields.key_hint'))
                            ->fontFamily(FontFamily::Mono)
                            ->extraAttributes(['dir' => 'ltr']),
                        TextEntry::make('last_checked_at')
                            ->label(__('ai::providers.fields.last_checked_at'))
                            ->since()
                            ->placeholder(__('ai::providers.fields.never')),
                        TextEntry::make('last_error_code')
                            ->label(__('ai::providers.fields.last_error'))
                            ->formatStateUsing(fn (?string $state): ?string => $state && Lang::has("ai::providers.errors.{$state}") ? __("ai::providers.errors.{$state}") : $state)
                            ->placeholder('-')
                            ->color('danger'),
                    ]),
                Section::make(__('ai::providers.sections.models'))
                    ->description(__('ai::providers.sections.models_help'))
                    ->visible(fn (?AiProvider $record): bool => ! empty($record?->models))
                    ->collapsible()
                    ->schema([
                        TextEntry::make('models')
                            ->hiddenLabel()
                            ->state(fn (?AiProvider $record): array => array_map(
                                fn (array $model): string => $model['name'] === $model['id'] ? $model['id'] : "{$model['name']} ({$model['id']})",
                                $record?->models ?? [],
                            ))
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->fontFamily(FontFamily::Mono)
                            ->extraAttributes(['dir' => 'ltr']),
                    ]),
            ]);
    }

    /** @return array<string, string> providers without a key yet, plus the one being edited */
    private static function providerOptions(?AiProvider $record): array
    {
        $configured = AiProvider::query()->pluck('provider')->map(fn ($p) => $p instanceof AiProviderName ? $p->value : $p)->all();
        $options = [];

        foreach (AiProviderName::cases() as $case) {
            if ($record?->provider === $case || ! in_array($case->value, $configured, true)) {
                $options[$case->value] = $case->label();
            }
        }

        return $options;
    }
}
