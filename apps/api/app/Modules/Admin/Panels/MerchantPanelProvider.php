<?php

namespace App\Modules\Admin\Panels;

use App\Modules\Admin\Http\Middleware\SyncTenantFromPanel;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Filament\Actions\Action;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * The merchant's panel: one shop at a time, the shop's results and approvals.
 *
 * Operators can open it to see exactly what a merchant sees. For them the top bar shows a clear
 * way back to the operator panel, where the plugin, connections, keys and activity live.
 */
final class MerchantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel
            ->id(User::MERCHANT_PANEL)
            ->path('merchant')
            ->tenant(Shop::class, slugAttribute: 'slug')
            ->tenantMiddleware([SyncTenantFromPanel::class], isPersistent: true)
            ->userMenuItems([
                'operator_panel' => Action::make('operatorPanel')
                    ->label(fn (): string => __('admin::panels.switch.operator'))
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->url(fn (): string => url('/'.User::OPERATOR_PANEL))
                    ->visible(fn (): bool => self::viewerIsOperator()),
            ])
            ->renderHook(PanelsRenderHook::TOPBAR_END, fn (): HtmlString => self::operatorShortcut());

        return PanelDefaults::apply($panel, 'Merchant');
    }

    private static function viewerIsOperator(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->is_operator;
    }

    private static function operatorShortcut(): HtmlString
    {
        if (! self::viewerIsOperator()) {
            return new HtmlString('');
        }

        return new HtmlString(Blade::render(
            '<x-filament::button tag="a" :href="$url" size="sm" color="gray" icon="heroicon-o-cog-6-tooth" data-operator-shortcut>{{ $label }}</x-filament::button>',
            ['url' => url('/'.User::OPERATOR_PANEL), 'label' => __('admin::panels.switch.operator')],
        ));
    }
}
