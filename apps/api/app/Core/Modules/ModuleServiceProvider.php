<?php

namespace App\Core\Modules;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use LogicException;

/**
 * Base provider for every module. It wires the module's folders by convention, so a module
 * only writes code for what is actually special about it:
 *
 *   Database/Migrations   loaded automatically
 *   lang/{locale}/*.php   under the "{slug}::" namespace
 *   resources/views       under the "{slug}::" namespace
 *   routes/api.php        prefixed /api/v1, "api" middleware
 *   routes/web.php        "web" middleware
 *
 * Override registerModule() and bootModule() for the rest.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    private ?ModuleManifest $module = null;

    final public function setModule(ModuleManifest $module): static
    {
        $this->module = $module;

        return $this;
    }

    final public function module(): ModuleManifest
    {
        return $this->module ?? throw new LogicException(static::class.' was registered without its module manifest.');
    }

    final public function register(): void
    {
        $this->registerModule();
    }

    final public function boot(): void
    {
        $module = $this->module();

        if (is_dir($migrations = $module->path('Database/Migrations'))) {
            $this->loadMigrationsFrom($migrations);
        }

        if (is_dir($lang = $module->path('lang'))) {
            $this->loadTranslationsFrom($lang, $module->slug);
        }

        if (is_dir($views = $module->path('resources/views'))) {
            $this->loadViewsFrom($views, $module->slug);
        }

        if (! $this->app->routesAreCached()) {
            if (is_file($api = $module->path('routes/api.php'))) {
                Route::prefix('api/v1')->middleware('api')->group($api);
            }

            if (is_file($web = $module->path('routes/web.php'))) {
                Route::middleware('web')->group($web);
            }
        }

        if ($this->app->runningInConsole() && ($commands = $this->moduleCommands()) !== []) {
            $this->commands($commands);
        }

        $this->bootModule();
    }

    protected function registerModule(): void {}

    protected function bootModule(): void {}

    /**
     * Artisan commands this module adds.
     *
     * @return list<class-string>
     */
    protected function moduleCommands(): array
    {
        return [];
    }
}
