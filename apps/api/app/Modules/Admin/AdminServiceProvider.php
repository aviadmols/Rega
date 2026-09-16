<?php

namespace App\Modules\Admin;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Admin\Console\CreateOperatorCommand;
use App\Modules\Admin\Panels\MerchantPanelProvider;
use App\Modules\Admin\Panels\OperatorPanelProvider;

final class AdminServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->register(OperatorPanelProvider::class);
        $this->app->register(MerchantPanelProvider::class);
    }

    protected function moduleCommands(): array
    {
        return [
            CreateOperatorCommand::class,
        ];
    }
}
