<?php

namespace App\Modules\Ai;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Ai\Contracts\ListsProviderModels;
use App\Modules\Ai\Support\SdkModelLister;

final class AiServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->bind(ListsProviderModels::class, SdkModelLister::class);
    }
}
