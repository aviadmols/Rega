<?php

namespace App\Core\Console;

use App\Core\Localization\Locales;
use App\Core\Modules\ModuleRepository;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Creates a complete, valid module skeleton, so every new module starts from the same shape
 * and passes the architecture, translation and smoke tests before it has any code.
 */
final class MakeModuleCommand extends Command
{
    protected $signature = 'make:module
        {name : StudlyCase module name, e.g. DisplayModels}
        {--requires= : Comma-separated modules this one depends on}';

    protected $description = 'Create a new module under app/Modules';

    public function handle(Filesystem $files, ModuleRepository $modules): int
    {
        $name = Str::studly((string) $this->argument('name'));
        $slug = Str::snake($name);
        $base = config('upsell.modules.path').DIRECTORY_SEPARATOR.$name;
        $namespace = config('upsell.modules.namespace').'\\'.$name;

        if ($files->exists($base)) {
            $this->components->error("Module [{$name}] already exists.");

            return self::FAILURE;
        }

        $requires = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('requires')))));

        foreach ($requires as $dependency) {
            if (! $modules->has($dependency)) {
                $this->components->error("Required module [{$dependency}] does not exist.");

                return self::FAILURE;
            }
        }

        foreach (['Actions', 'Contracts', 'Models', 'Database/Migrations', 'Tests'] as $directory) {
            $files->ensureDirectoryExists("{$base}/{$directory}");
        }

        foreach (['Actions', 'Contracts', 'Models', 'Database/Migrations'] as $directory) {
            $files->put("{$base}/{$directory}/.gitkeep", '');
        }

        $files->put("{$base}/module.json", json_encode([
            'name' => $name,
            'enabled' => true,
            'requires' => $requires,
            'provider' => "{$name}ServiceProvider",
            'features' => [],
            'settings' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $files->put("{$base}/{$name}ServiceProvider.php", $this->provider($namespace, $name));

        foreach (Locales::supported() as $locale) {
            $files->ensureDirectoryExists("{$base}/lang/{$locale}");
            $files->put("{$base}/lang/{$locale}/module.php", $this->moduleLang($name));
        }

        $files->put("{$base}/Tests/{$name}ModuleTest.php", $this->test($namespace, $name, $slug));

        $this->components->info("Module [{$name}] created at app/Modules/{$name}.");
        $this->components->bulletList([
            'Declare features and settings in module.json.',
            'Translate module.name in every lang/{locale}/module.php.',
            'Import other modules only from their Contracts, Models, Enums or Events namespaces, and list them in "requires".',
        ]);

        return self::SUCCESS;
    }

    private function provider(string $namespace, string $name): string
    {
        return <<<PHP
        <?php

        namespace {$namespace};

        use App\Core\Modules\ModuleServiceProvider;

        final class {$name}ServiceProvider extends ModuleServiceProvider
        {
            protected function registerModule(): void
            {
                //
            }

            protected function bootModule(): void
            {
                //
            }
        }

        PHP;
    }

    private function moduleLang(string $name): string
    {
        $title = Str::headline($name);

        return <<<PHP
        <?php

        return [
            'name' => '{$title}',
        ];

        PHP;
    }

    private function test(string $namespace, string $name, string $slug): string
    {
        return <<<PHP
        <?php

        namespace {$namespace}\Tests;

        use App\Core\Modules\ModuleRepository;
        use Tests\TestCase;

        final class {$name}ModuleTest extends TestCase
        {
            public function test_module_is_discovered_and_enabled(): void
            {
                \$module = app(ModuleRepository::class)->get('{$name}');

                \$this->assertNotNull(\$module);
                \$this->assertTrue(\$module->enabled);
                \$this->assertSame('{$slug}', \$module->slug);
            }
        }

        PHP;
    }
}
