<?php

namespace App\Modules\Admin\Filament\Operator\Pages;

use App\Core\Features\FeatureDefinition;
use App\Core\Features\FeatureManager;
use App\Core\Features\FeatureRegistry;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRepository;
use App\Core\Settings\InvalidSettingValue;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingManager;
use App\Core\Settings\SettingRegistry;
use App\Core\Settings\SettingType;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * Every feature flag and setting every enabled module declared, for the whole system or for
 * one shop. Built from the registries, so a module that declares a new cap gets it on this
 * screen without anyone touching this class.
 */
class Configuration extends Page implements HasForms
{
    use InteractsWithForms;

    private const INHERIT = 'inherit';

    /** Filament treats an empty option value as "nothing selected", so global needs a real value. */
    private const GLOBAL_SCOPE = 'global';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'configuration';

    protected string $view = 'admin::operator.configuration';

    /** Shop id, or null for the global values. */
    #[Url]
    public ?string $shop = null;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('admin::configuration.title');
    }

    public function getTitle(): string
    {
        return __('admin::configuration.title');
    }

    public function getSubheading(): ?string
    {
        $shop = $this->selectedShop();

        return $shop === null
            ? __('admin::configuration.scope_global_help')
            : __('admin::configuration.scope_shop_help', ['shop' => $shop->name]);
    }

    public function mount(): void
    {
        if ($this->shop !== null && $this->selectedShop() === null) {
            $this->shop = null;
        }

        $state = ['scope' => $this->shop ?? self::GLOBAL_SCOPE];

        foreach ($this->features() as $definition) {
            $override = $this->featureManager()->overrideFor($definition->key(), $this->shop);
            $state[self::field('f', $definition->key())] = $override === null ? self::INHERIT : ($override ? 'on' : 'off');
        }

        foreach ($this->settings() as $definition) {
            $override = $this->settingManager()->overrideFor($definition->key(), $this->shop);
            $state[self::field('s', $definition->key())] = match (true) {
                $override === null => null,
                is_bool($override) => $override ? 'on' : 'off',
                default => (string) $override,
            };
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('admin::configuration.scope'))
                    ->schema([
                        Select::make('scope')
                            ->label(__('admin::configuration.scope'))
                            ->hiddenLabel()
                            ->options(fn (): array => [self::GLOBAL_SCOPE => __('admin::configuration.scope_global')] + Shop::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->selectablePlaceholder(false)
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn (?string $state) => $this->redirect(self::getUrl(
                                ['shop' => in_array($state, [null, '', self::GLOBAL_SCOPE], true) ? null : $state],
                            ))),
                    ]),
                ...$this->moduleSections(),
            ]);
    }

    public function save(): void
    {
        $features = [];
        $settings = [];
        $errors = [];

        foreach ($this->features() as $definition) {
            $features[$definition->key()] = $this->data[self::field('f', $definition->key())] ?? self::INHERIT;
        }

        // Validate everything first, so a single bad value saves nothing.
        foreach ($this->settings() as $definition) {
            $field = self::field('s', $definition->key());
            $raw = $this->data[$field] ?? null;

            if ($raw === null || $raw === '' || $raw === self::INHERIT) {
                $settings[$definition->key()] = null;

                continue;
            }

            if ($definition->type === SettingType::Bool) {
                $raw = $raw === 'on';
            }

            try {
                $settings[$definition->key()] = $definition->normalize($raw);
            } catch (InvalidSettingValue $e) {
                $errors["data.{$field}"] = $e->translated();
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($features, $settings): void {
            foreach ($features as $key => $choice) {
                $choice === self::INHERIT
                    ? $this->featureManager()->clearOverride($key, $this->shop)
                    : $this->featureManager()->override($key, $choice === 'on', $this->shop);
            }

            foreach ($settings as $key => $value) {
                $value === null
                    ? $this->settingManager()->clear($key, $this->shop)
                    : $this->settingManager()->set($key, $value, $this->shop);
            }
        });

        Notification::make()->success()->title(__('admin::configuration.saved'))->send();

        $this->mount();
    }

    /**
     * What a shop's own settings are, in the order a shop owner thinks about them. The same list
     * the merchant's screen is built from, so the two screens never drift apart.
     *
     * @var array<string, list<string>>
     */
    public const SHOP_GROUPS = [
        'shown' => [
            'widget.on_products',
            'widget.on_content',
            'widget.layout',
            'widget.max_products',
            'widget.popularity',
            'widget.promises',
        ],
        'placement' => [
            'widget.product_selector',
            'widget.product_position',
            'widget.content_selector',
            'widget.content_position',
            'widget.floating_fallback',
        ],
        'assistant' => [
            'assistant.on_products',
            'assistant.on_content',
        ],
        'whatsapp' => [
            'widget.whatsapp',
            'widget.whatsapp_number',
            'widget.whatsapp_title',
            'widget.whatsapp_button',
            'widget.whatsapp_message',
            'widget.whatsapp_offline_note',
            'widget.whatsapp_when_offline',
        ],
        'signup' => [
            'shoppers.recent_products',
            'shoppers.signup',
            'shoppers.signup_title',
            'shoppers.signup_consent',
            'shoppers.signup_note',
        ],
    ];

    /**
     * With a shop in hand this screen had sixty-odd fields on it, most of them platform tuning
     * that has nothing to do with running a store. The shop's own settings come first, in their
     * groups; everything else is folded away under one heading, open only when it is wanted.
     *
     * @return list<Component>
     */
    protected function moduleSections(): array
    {
        $features = collect($this->features())->keyBy(fn (FeatureDefinition $d): string => $d->key());
        $settings = collect($this->settings())->keyBy(fn (SettingDefinition $d): string => $d->key());

        $sections = [];
        $inGroups = [];

        foreach (self::SHOP_GROUPS as $group => $keys) {
            $fields = [];

            foreach ($keys as $key) {
                $field = match (true) {
                    $features->has($key) => $this->featureField($features->get($key)),
                    $settings->has($key) => $this->settingField($settings->get($key)),
                    default => null,
                };

                if ($field !== null) {
                    $fields[] = $field;
                    $inGroups[] = $key;
                }
            }

            if ($fields !== []) {
                $sections[] = Section::make(__("admin::configuration.groups.{$group}.title"))
                    ->description(__("admin::configuration.groups.{$group}.help"))
                    ->columns(2)
                    ->schema($fields);
            }
        }

        $rest = $this->restByModule($inGroups);

        if ($rest !== []) {
            $sections[] = Section::make(__('admin::configuration.groups.advanced.title'))
                ->description(__('admin::configuration.groups.advanced.help'))
                ->collapsible()
                ->collapsed()
                ->schema($rest);
        }

        return $sections;
    }

    /**
     * Everything the groups did not take, still one section per module, inside the fold.
     *
     * @param  list<string>  $taken
     * @return list<Component>
     */
    private function restByModule(array $taken): array
    {
        $sections = [];

        foreach (app(ModuleRepository::class)->enabled() as $module) {
            $fields = [
                ...array_map(
                    fn (FeatureDefinition $d) => $this->featureField($d),
                    array_values(array_filter($this->features($module), fn (FeatureDefinition $d): bool => ! in_array($d->key(), $taken, true))),
                ),
                ...array_map(
                    fn (SettingDefinition $d) => $this->settingField($d),
                    array_values(array_filter($this->settings($module), fn (SettingDefinition $d): bool => ! in_array($d->key(), $taken, true))),
                ),
            ];

            if ($fields !== []) {
                $sections[] = Section::make(__($module->nameKey()))
                    ->columns(2)
                    ->schema($fields);
            }
        }

        return $sections;
    }

    protected function featureField(FeatureDefinition $definition): Select
    {
        $inherited = $this->shop === null
            ? $definition->default
            : $this->featureManager()->enabled($definition->key());

        return Select::make(self::field('f', $definition->key()))
            ->label(__($definition->labelKey()))
            ->helperText(Lang::has($definition->descriptionKey()) ? __($definition->descriptionKey()) : null)
            ->options([
                self::INHERIT => __('admin::configuration.inherit', ['value' => __($inherited ? 'admin::configuration.on' : 'admin::configuration.off')]),
                'on' => __('admin::configuration.on'),
                'off' => __('admin::configuration.off'),
            ])
            ->selectablePlaceholder(false);
    }

    protected function settingField(SettingDefinition $definition): Component
    {
        $name = self::field('s', $definition->key());
        $inherited = $this->shop === null
            ? $definition->default
            : $this->settingManager()->get($definition->key());
        $label = __($definition->labelKey());
        $help = array_filter([
            Lang::has($definition->descriptionKey()) ? __($definition->descriptionKey()) : null,
            $this->rangeHint($definition),
        ]);

        $field = match ($definition->type) {
            SettingType::Bool => Select::make($name)
                ->options([
                    'on' => __('admin::configuration.on'),
                    'off' => __('admin::configuration.off'),
                ])
                ->placeholder(__('admin::configuration.inherit', ['value' => __($inherited ? 'admin::configuration.on' : 'admin::configuration.off')])),
            SettingType::Enum => Select::make($name)
                ->options(array_combine($definition->options, array_map(fn (string $o) => $this->optionLabel($definition, $o), $definition->options)))
                ->placeholder(__('admin::configuration.inherit', ['value' => $this->optionLabel($definition, (string) $inherited)])),
            SettingType::Int, SettingType::Float => TextInput::make($name)
                ->numeric()
                ->inputMode($definition->type === SettingType::Int ? 'numeric' : 'decimal')
                ->placeholder(__('admin::configuration.inherit', ['value' => (string) $inherited]))
                ->suffix($definition->unit !== null && Lang::has("core::settings.units.{$definition->unit}") ? __("core::settings.units.{$definition->unit}") : null),
            SettingType::String => TextInput::make($name)
                ->placeholder(__('admin::configuration.inherit', ['value' => (string) $inherited])),
        };

        return $field
            ->label($label)
            ->helperText($help === [] ? null : implode(' ', $help));
    }

    private function rangeHint(SettingDefinition $definition): ?string
    {
        if (! in_array($definition->type, [SettingType::Int, SettingType::Float], true)) {
            return null;
        }

        if ($definition->min !== null && $definition->max !== null) {
            return __('admin::configuration.range', ['min' => $definition->min, 'max' => $definition->max]);
        }

        return null;
    }

    private function optionLabel(SettingDefinition $definition, string $option): string
    {
        $key = "{$definition->module}::settings.{$definition->name}.options.{$option}";

        return Lang::has($key) ? __($key) : $option;
    }

    /** @return list<FeatureDefinition> */
    protected function features(?ModuleManifest $module = null): array
    {
        $registry = app(FeatureRegistry::class);

        return array_values($module === null ? $registry->all() : $registry->forModule($module->slug));
    }

    /**
     * Global-only settings are hidden while a shop is selected: a shop cannot override them.
     *
     * @return list<SettingDefinition>
     */
    protected function settings(?ModuleManifest $module = null): array
    {
        $registry = app(SettingRegistry::class);
        $definitions = $module === null ? $registry->all() : $registry->forModule($module->slug);

        return array_values(array_filter(
            $definitions,
            fn (SettingDefinition $d): bool => $this->shop === null || $d->isShopOverridable(),
        ));
    }

    private function selectedShop(): ?Shop
    {
        return $this->shop === null ? null : Shop::query()->find($this->shop);
    }

    /** Dots would nest the form state, so keys become flat field names. */
    private static function field(string $kind, string $key): string
    {
        return $kind.'__'.str_replace('.', '__', $key);
    }

    private function featureManager(): FeatureManager
    {
        return app(FeatureManager::class);
    }

    private function settingManager(): SettingManager
    {
        return app(SettingManager::class);
    }
}
