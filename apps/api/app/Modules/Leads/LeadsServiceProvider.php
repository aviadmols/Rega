<?php

namespace App\Modules\Leads;

use App\Core\Facades\Settings;
use App\Core\Modules\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class LeadsServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        // A form that can be posted to as fast as a script wants is a form that will be.
        RateLimiter::for('widget-lead', fn (Request $request): Limit => Limit::perMinute(
            (int) Settings::get('leads.steps_per_minute'),
        )->by($request->route('site').'|'.$request->ip()));
    }
}
