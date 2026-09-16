<?php

namespace Tests\Core;

use App\Core\Modules\Exceptions\ModuleDependencyException;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRepository;
use PHPUnit\Framework\TestCase;

final class ModuleRepositoryTest extends TestCase
{
    public function test_modules_come_after_the_modules_they_require(): void
    {
        $repository = new ModuleRepository([
            $this->module('Reports', ['Events', 'Tenancy']),
            $this->module('Events', ['Tenancy']),
            $this->module('Tenancy'),
        ]);

        $this->assertSame(['Tenancy', 'Events', 'Reports'], array_keys($repository->all()));
    }

    public function test_a_missing_dependency_stops_the_boot(): void
    {
        $this->expectException(ModuleDependencyException::class);
        $this->expectExceptionMessage('Module [Reports] requires [Events]');

        new ModuleRepository([$this->module('Reports', ['Events'])]);
    }

    public function test_a_disabled_dependency_stops_the_boot(): void
    {
        $this->expectException(ModuleDependencyException::class);

        new ModuleRepository([
            $this->module('Reports', ['Events']),
            $this->module('Events', enabled: false),
        ]);
    }

    public function test_a_disabled_module_may_require_anything(): void
    {
        $repository = new ModuleRepository([$this->module('Draft', ['DoesNotExistYet'], enabled: false)]);

        $this->assertSame([], $repository->enabled());
        $this->assertTrue($repository->has('Draft'));
    }

    public function test_a_dependency_cycle_is_reported_with_its_path(): void
    {
        $this->expectException(ModuleDependencyException::class);
        $this->expectExceptionMessage('A -> B -> A');

        new ModuleRepository([
            $this->module('A', ['B']),
            $this->module('B', ['A']),
        ]);
    }

    public function test_it_finds_modules_by_slug(): void
    {
        $repository = new ModuleRepository([$this->module('DisplayModels')]);

        $this->assertSame('DisplayModels', $repository->findBySlug('display_models')?->name);
        $this->assertNull($repository->findBySlug('nope'));
    }

    /** @param list<string> $requires */
    private function module(string $name, array $requires = [], bool $enabled = true): ModuleManifest
    {
        return new ModuleManifest(
            name: $name,
            slug: strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name)),
            path: '/modules/'.$name,
            namespace: 'App\\Modules\\'.$name,
            enabled: $enabled,
            requires: $requires,
            provider: null,
            features: [],
            settings: [],
        );
    }
}
