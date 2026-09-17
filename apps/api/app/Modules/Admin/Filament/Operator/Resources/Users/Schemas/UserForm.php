<?php

namespace App\Modules\Admin\Filament\Operator\Resources\Users\Schemas;

use App\Core\Localization\Locales;
use App\Modules\Admin\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

final class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('admin::users.sections.account'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('admin::users.fields.name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label(__('admin::users.fields.email'))
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('password')
                            ->label(__('admin::users.fields.password'))
                            ->helperText(fn (string $operation): ?string => $operation === 'edit' ? __('admin::users.fields.password_keep') : null)
                            ->password()
                            // Without this, a browser can autofill the signed-in operator's saved
                            // password here and silently change the password of the user being edited.
                            ->autocomplete('new-password')
                            ->revealable()
                            ->minLength(User::MIN_PASSWORD_LENGTH)
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state)),
                        Select::make('locale')
                            ->label(__('admin::users.fields.locale'))
                            ->options(self::localeOptions())
                            ->placeholder(__('admin::users.fields.locale_auto')),
                    ]),
                Section::make(__('admin::users.sections.access'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_operator')
                            ->label(__('admin::users.fields.is_operator'))
                            ->helperText(__('admin::users.fields.is_operator_help')),
                        Select::make('shops')
                            ->label(__('admin::users.fields.shops'))
                            ->helperText(__('admin::users.fields.shops_help'))
                            ->relationship('shops', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable(),
                    ]),
            ]);
    }

    /** @return array<string, string> */
    private static function localeOptions(): array
    {
        $options = [];

        foreach (Locales::supported() as $locale) {
            $options[$locale] = __("admin::panels.locale.names.{$locale}");
        }

        return $options;
    }
}
