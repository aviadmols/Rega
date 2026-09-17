<?php

namespace App\Modules\Analytics;

use App\Core\Facades\Settings;
use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Support\BeaconSchema;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class AnalyticsServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->singleton(BeaconSchema::class);
    }

    protected function bootModule(): void
    {
        RateLimiter::for('widget-beacons', fn (Request $request): Limit => Limit::perMinute((int) Settings::get('analytics.beacons_per_minute'))
            ->by('beacon:'.$request->ip().'|'.$request->route('site')));

        RateLimiter::for('plugin-api', fn (Request $request): Limit => Limit::perMinute(120)
            ->by('plugin:'.$request->route('site')));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('model:prune', ['--model' => [AnalyticsEvent::class]])->dailyAt('03:45')->name('analytics:prune');
        });
    }
}
