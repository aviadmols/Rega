<?php

namespace App\Modules\Connections\Filament\Operator\Resources\StoreConnections\Schemas;

use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Connections\Models\StoreConnection;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Illuminate\Support\Facades\Lang;

final class StoreConnectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('connections::connections.sections.connection'))
                    ->description(__('connections::connections.sections.connection_help'))
                    ->columns(2)
                    ->schema([
                        Select::make('shop_id')
                            ->label(__('connections::connections.fields.shop'))
                            ->relationship('shop', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->disabledOn('edit'),
                        Select::make('platform')
                            ->label(__('connections::connections.fields.platform'))
                            ->options(['woocommerce' => 'WooCommerce'])
                            ->default('woocommerce')
                            ->selectablePlaceholder(false)
                            ->required(),
                        TextInput::make('site_url')
                            ->label(__('connections::connections.fields.site_url'))
                            ->helperText(__('connections::connections.fields.site_url_help'))
                            ->url()
                            ->required()
                            ->maxLength(255)
                            ->extraInputAttributes(['dir' => 'ltr']),
                        TextInput::make('access_token')
                            ->label(__('connections::connections.fields.access_token'))
                            ->helperText(fn (string $operation): string => $operation === 'edit'
                                ? __('connections::connections.fields.access_token_keep')
                                : __('connections::connections.fields.access_token_help'))
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->extraInputAttributes(['dir' => 'ltr']),
                    ]),
                Section::make(__('connections::connections.sections.site'))
                    ->columns(4)
                    ->visibleOn('edit')
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('connections::connections.fields.status'))
                            ->badge()
                            ->color(fn (ConnectionStatus $state): string => $state->color())
                            ->formatStateUsing(fn (ConnectionStatus $state): string => $state->label()),
                        TextEntry::make('last_checked_at')
                            ->label(__('connections::connections.fields.last_checked_at'))
                            ->since()
                            ->placeholder(__('connections::connections.fields.never')),
                        TextEntry::make('last_error_code')
                            ->label(__('connections::connections.fields.last_error'))
                            ->formatStateUsing(fn (?string $state): ?string => self::errorLabel($state))
                            ->placeholder('-')
                            ->color('danger'),
                        TextEntry::make('token_prefix')
                            ->label(__('connections::connections.fields.token'))
                            ->formatStateUsing(fn (?string $state): string => $state ? $state.'…' : '-')
                            ->fontFamily(FontFamily::Mono),
                        self::info('site.name', __('connections::connections.fields.site_name')),
                        self::info('plugin.version', __('connections::connections.fields.plugin_version')),
                        self::info('site.wordpress', 'WordPress'),
                        self::info('woocommerce.version', 'WooCommerce'),
                        self::info('counts.products.publish', __('connections::connections.fields.published_products')),
                        self::info('counts.variations', __('connections::connections.fields.variations')),
                        self::info('counts.product_categories', __('connections::connections.fields.categories')),
                        self::info('site.locale', __('connections::connections.fields.locale')),
                    ]),
            ]);
    }

    private static function info(string $path, string $label): TextEntry
    {
        return TextEntry::make('site_info.'.$path)
            ->label($label)
            ->state(fn (?StoreConnection $record): mixed => $record?->info($path))
            ->placeholder('-');
    }

    private static function errorLabel(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $key = "connections::connections.errors.{$code}";

        return Lang::has($key) ? __($key) : $code;
    }
}
