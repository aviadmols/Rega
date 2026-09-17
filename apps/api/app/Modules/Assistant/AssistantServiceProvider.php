<?php

namespace App\Modules\Assistant;

use App\Core\Facades\Settings;
use App\Core\Modules\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class AssistantServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        //
    }

    protected function bootModule(): void
    {
        RateLimiter::for('assistant-ask', fn (Request $request): Limit => Limit::perMinute((int) Settings::get('assistant.asks_per_minute'))
            ->by('assistant-ask:'.$request->ip().'|'.$request->route('site')));
    }
}
