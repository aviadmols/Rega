<?php

namespace App\Modules\Connections;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Connections\Console\BundlePluginCommand;
use App\Modules\Connections\Contracts\StoreFeed;
use App\Modules\Connections\Support\PluginStoreFeed;

final class ConnectionsServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->bind(StoreFeed::class, PluginStoreFeed::class);
    }

    protected function moduleCommands(): array
    {
        return [
            BundlePluginCommand::class,
        ];
    }
}
