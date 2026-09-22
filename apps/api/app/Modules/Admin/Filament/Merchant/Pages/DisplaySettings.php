<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use App\Core\Features\FeatureDefinition;
use App\Core\Modules\ModuleManifest;
use App\Core\Settings\SettingDefinition;
use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Admin\Filament\Operator\Pages\Configuration as OperatorConfiguration;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * What a shop owner may change about their own widget: whether it shows at all, where on the page
 * it sits, which of the two layouts it uses, what the assistant may answer, and the wording of the
 * WhatsApp strip and the sign-up.
 *
 * The same screen the operator uses, with two things taken away. The shop is the one in the
 * address and cannot be switched, and only the keys named here are on it: a merchant never sees
 * a spending cap, a sync limit or anything about enrichment. The allow-list decides what is read
 * and what may be written, so a field that is not on the screen cannot be saved from it either.
 */
final class DisplaySettings extends OperatorConfiguration
{
    use LocksShopToPanelTenant;

    /** Switches a shop owner decides for themselves. */
    private const FEATURES = [
        'widget.on_products',
        'widget.on_content',
        'widget.popularity',
        'widget.promises',
        'widget.whatsapp',
        'assistant.on_products',
        'assistant.on_content',
        'shoppers.recent_products',
        'shoppers.signup',
    ];

    /** Wording and placement. Never a cap, a limit or a price. */
    private const SETTINGS = [
        'widget.layout',
        'widget.product_selector',
        'widget.product_position',
        'widget.content_selector',
        'widget.content_position',
        'widget.floating_fallback',
        'widget.max_products',
        'widget.whatsapp_number',
        'widget.whatsapp_title',
        'widget.whatsapp_button',
        'widget.whatsapp_message',
        'widget.whatsapp_offline_note',
        'widget.whatsapp_hours',
        'widget.whatsapp_hours_friday',
        'widget.whatsapp_hours_saturday',
        'widget.whatsapp_timezone',
        'widget.whatsapp_when_offline',
        'shoppers.signup_title',
        'shoppers.signup_consent',
        'shoppers.signup_note',
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'display';

    public static function getNavigationLabel(): string
    {
        return __('admin::configuration.display_title');
    }

    public function getTitle(): string
    {
        return __('admin::configuration.display_title');
    }

    public function getSubheading(): ?string
    {
        return __('admin::configuration.display_help');
    }

    public function mount(): void
    {
        // The trait also declares mount(); this one wins, and the parent still fills the form.
        $this->lockShopToTenant();
        parent::mount();
    }

    /** No shop picker: the shop is the one whose panel this is. */
    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components($this->moduleSections());
    }

    /** @return list<FeatureDefinition> */
    protected function features(?ModuleManifest $module = null): array
    {
        return array_values(array_filter(
            parent::features($module),
            fn (FeatureDefinition $definition): bool => in_array($definition->key(), self::FEATURES, true),
        ));
    }

    /** @return list<SettingDefinition> */
    protected function settings(?ModuleManifest $module = null): array
    {
        return array_values(array_filter(
            parent::settings($module),
            fn (SettingDefinition $definition): bool => in_array($definition->key(), self::SETTINGS, true),
        ));
    }
}
