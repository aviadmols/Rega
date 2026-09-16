<?php

namespace Tests\Core;

use App\Core\Modules\ModuleManifest;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

final class MakeModuleCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'upsell-make-'.bin2hex(random_bytes(4));
        mkdir($this->root);
        config(['upsell.modules.path' => $this->root]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_it_creates_a_module_that_the_kernel_can_load(): void
    {
        $this->artisan('make:module', ['name' => 'display-models', '--requires' => 'Tenancy'])
            ->assertSuccessful();

        $base = $this->root.DIRECTORY_SEPARATOR.'DisplayModels';

        foreach ([
            'module.json',
            'DisplayModelsServiceProvider.php',
            'lang/he/module.php',
            'lang/en/module.php',
            'Tests/DisplayModelsModuleTest.php',
            'Database/Migrations/.gitkeep',
            'Contracts/.gitkeep',
        ] as $file) {
            $this->assertFileExists($base.DIRECTORY_SEPARATOR.$file);
        }

        $manifest = ModuleManifest::fromFile($base.DIRECTORY_SEPARATOR.'module.json', 'App\\Modules');
        $this->assertSame(['Tenancy'], $manifest->requires);
        $this->assertSame('App\\Modules\\DisplayModels\\DisplayModelsServiceProvider', $manifest->provider);

        $provider = (string) file_get_contents($base.DIRECTORY_SEPARATOR.'DisplayModelsServiceProvider.php');
        $this->assertStringContainsString('namespace App\\Modules\\DisplayModels;', $provider);
        $this->assertStringContainsString('extends ModuleServiceProvider', $provider);
    }

    public function test_it_refuses_an_unknown_dependency(): void
    {
        $this->artisan('make:module', ['name' => 'Reports', '--requires' => 'Nope'])
            ->assertFailed();

        $this->assertDirectoryDoesNotExist($this->root.DIRECTORY_SEPARATOR.'Reports');
    }

    public function test_it_refuses_to_overwrite_a_module(): void
    {
        mkdir($this->root.DIRECTORY_SEPARATOR.'Reports');

        $this->artisan('make:module', ['name' => 'Reports'])->assertFailed();
    }
}
