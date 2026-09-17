<?php

namespace App\Modules\Ai;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ListsProviderModels;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Support\MonthlySpendGuard;
use App\Modules\Ai\Support\SdkChatModel;
use App\Modules\Ai\Support\SdkModelLister;

final class AiServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->bind(ListsProviderModels::class, SdkModelLister::class);
        $this->app->bind(SpendGuard::class, MonthlySpendGuard::class);
        $this->app->bind(ChatModel::class, SdkChatModel::class);
    }
}
