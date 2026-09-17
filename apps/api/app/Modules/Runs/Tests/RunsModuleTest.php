<?php

namespace App\Modules\Runs\Tests;

use App\Core\Modules\ModuleRepository;
use Tests\TestCase;

final class RunsModuleTest extends TestCase
{
    public function test_module_is_discovered_and_enabled(): void
    {
        $module = app(ModuleRepository::class)->get('Runs');

        $this->assertNotNull($module);
        $this->assertTrue($module->enabled);
        $this->assertSame('runs', $module->slug);
    }
}
