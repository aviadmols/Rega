<?php

namespace App\Modules\Admin;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Admin\Console\CreateOperatorCommand;
use App\Modules\Admin\Http\Responses\RoleAwareLoginResponse;
use App\Modules\Admin\Panels\MerchantPanelProvider;
use App\Modules\Admin\Panels\OperatorPanelProvider;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;

final class AdminServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->register(OperatorPanelProvider::class);
        $this->app->register(MerchantPanelProvider::class);

        // Replaces Filament's default, which always returns to the panel the login was on.
        $this->app->bind(LoginResponse::class, RoleAwareLoginResponse::class);
    }

    protected function moduleCommands(): array
    {
        return [
            CreateOperatorCommand::class,
        ];
    }
}
