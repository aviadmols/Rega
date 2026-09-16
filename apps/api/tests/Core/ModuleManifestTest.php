<?php

namespace Tests\Core;

use App\Core\Modules\Exceptions\InvalidModuleManifest;
use App\Core\Modules\ModuleManifest;
use App\Core\Settings\SettingScope;
use App\Core\Settings\SettingType;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleManifestTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'upsell-manifest-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_it_parses_a_complete_manifest(): void
    {
        $file = $this->manifest('DisplayModels', [
            'name' => 'DisplayModels',
            'requires' => ['Tenancy'],
            'provider' => 'DisplayModelsServiceProvider',
            'features' => [['name' => 'compare', 'default' => false]],
            'settings' => [
                ['name' => 'retire_after_exposures', 'type' => 'int', 'default' => 150, 'min' => 20, 'max' => 5000, 'unit' => 'exposures'],
                ['name' => 'tone', 'type' => 'enum', 'options' => ['friendly', 'neutral'], 'default' => 'friendly', 'scope' => 'global'],
            ],
        ]);

        $manifest = ModuleManifest::fromFile($file, 'App\\Modules');

        $this->assertSame('DisplayModels', $manifest->name);
        $this->assertSame('display_models', $manifest->slug);
        $this->assertSame('App\\Modules\\DisplayModels', $manifest->namespace);
        $this->assertSame('App\\Modules\\DisplayModels\\DisplayModelsServiceProvider', $manifest->provider);
        $this->assertSame(['Tenancy'], $manifest->requires);
        $this->assertTrue($manifest->enabled);

        $this->assertSame('display_models.compare', $manifest->features[0]->key());
        $this->assertFalse($manifest->features[0]->default);
        $this->assertSame('display_models::features.compare.label', $manifest->features[0]->labelKey());

        [$retire, $tone] = $manifest->settings;
        $this->assertSame(SettingType::Int, $retire->type);
        $this->assertSame(150, $retire->default);
        $this->assertSame(SettingScope::Shop, $retire->scope, 'scope defaults to shop');
        $this->assertSame('exposures', $retire->unit);
        $this->assertSame(SettingScope::Global, $tone->scope);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidManifests(): array
    {
        return [
            'name differs from directory' => [['name' => 'Other'], 'must equal the directory name'],
            'requires is not a list' => [['name' => 'Probe', 'requires' => 'Tenancy'], '"requires" must be a list'],
            'feature name not snake_case' => [['name' => 'Probe', 'features' => [['name' => 'BadName', 'default' => true]]], 'snake_case'],
            'feature default not boolean' => [['name' => 'Probe', 'features' => [['name' => 'x', 'default' => 'yes']]], 'boolean "default"'],
            'unknown setting type' => [['name' => 'Probe', 'settings' => [['name' => 'x', 'type' => 'money', 'default' => 1]]], 'unknown "type"'],
            'min greater than max' => [['name' => 'Probe', 'settings' => [['name' => 'x', 'type' => 'int', 'default' => 5, 'min' => 10, 'max' => 1]]], 'min greater than max'],
            'default outside range' => [['name' => 'Probe', 'settings' => [['name' => 'x', 'type' => 'int', 'default' => 500, 'min' => 1, 'max' => 10]]], 'default is invalid (above_max)'],
            'enum without options' => [['name' => 'Probe', 'settings' => [['name' => 'x', 'type' => 'enum', 'default' => 'a']]], 'non-empty list of strings'],
            'enum default not an option' => [['name' => 'Probe', 'settings' => [['name' => 'x', 'type' => 'enum', 'options' => ['a'], 'default' => 'b']]], 'default is invalid (not_option)'],
            'missing default' => [['name' => 'Probe', 'settings' => [['name' => 'x', 'type' => 'int']]], 'needs a scalar "default"'],
        ];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('invalidManifests')]
    public function test_it_rejects_invalid_manifests(array $data, string $message): void
    {
        $file = $this->manifest('Probe', $data);

        $this->expectException(InvalidModuleManifest::class);
        $this->expectExceptionMessage($message);

        ModuleManifest::fromFile($file, 'App\\Modules');
    }

    public function test_it_rejects_malformed_json(): void
    {
        $dir = $this->root.DIRECTORY_SEPARATOR.'Probe';
        mkdir($dir, recursive: true);
        file_put_contents($dir.DIRECTORY_SEPARATOR.'module.json', '{ not json');

        $this->expectException(InvalidModuleManifest::class);
        $this->expectExceptionMessage('not valid JSON');

        ModuleManifest::fromFile($dir.DIRECTORY_SEPARATOR.'module.json', 'App\\Modules');
    }

    /** @param array<string, mixed> $data */
    private function manifest(string $directory, array $data): string
    {
        $dir = $this->root.DIRECTORY_SEPARATOR.$directory;
        mkdir($dir, recursive: true);
        file_put_contents($file = $dir.DIRECTORY_SEPARATOR.'module.json', json_encode($data));

        return $file;
    }
}
