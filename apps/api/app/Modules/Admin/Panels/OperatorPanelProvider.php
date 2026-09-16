<?php

namespace App\Modules\Admin\Panels;

use App\Modules\Admin\Http\Middleware\EnterOperatorScope;
use App\Modules\Admin\Models\User;
use Filament\Panel;
use Filament\PanelProvider;

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
            ->path('operator');

        return PanelDefaults::apply($panel, 'Operator', [EnterOperatorScope::class]);
    }
}
