<?php

namespace App\Modules\Admin\Panels;

use App\Modules\Admin\Http\Middleware\SyncTenantFromPanel;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Filament\Panel;
use Filament\PanelProvider;

/**
 * The merchant's panel: one shop at a time, the shop's results and approvals.
 */
final class MerchantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel
            ->id(User::MERCHANT_PANEL)
            ->path('merchant')
            ->tenant(Shop::class, slugAttribute: 'slug')
            ->tenantMiddleware([SyncTenantFromPanel::class], isPersistent: true);

        return PanelDefaults::apply($panel, 'Merchant');
    }
}
