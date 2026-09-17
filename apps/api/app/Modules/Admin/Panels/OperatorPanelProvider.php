<?php

namespace App\Modules\Admin\Panels;

use App\Modules\Admin\Http\Middleware\EnterOperatorScope;
use App\Modules\Admin\Models\User;
use Filament\Actions\Action;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;

/**
 * The system operator's panel: every shop, every setting, costs, models and prompts.
 */
final class OperatorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel
            ->default()
            ->id(User::OPERATOR_PANEL)
            ->path('operator')
            ->userMenuItems([
                'merchant_view' => Action::make('merchantView')
                    ->label(fn (): string => __('admin::panels.switch.merchant'))
                    ->icon(Heroicon::OutlinedBuildingStorefront)
                    ->url(fn (): string => url('/'.User::MERCHANT_PANEL)),
            ]);

        return PanelDefaults::apply($panel, 'Operator', [EnterOperatorScope::class]);
    }
}
