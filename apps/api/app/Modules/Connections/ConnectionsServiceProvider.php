<?php

namespace App\Modules\Connections;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Connections\Console\BundlePluginCommand;

final class ConnectionsServiceProvider extends ModuleServiceProvider
{
    protected function moduleCommands(): array
    {
        return [
            BundlePluginCommand::class,
        ];
    }
}
