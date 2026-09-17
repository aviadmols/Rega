<?php

namespace App\Modules\Analytics\Tests;

use App\Core\Modules\ModuleRepository;
use Tests\TestCase;

final class AnalyticsModuleTest extends TestCase
{
    public function test_module_is_discovered_and_enabled(): void
    {
        $module = app(ModuleRepository::class)->get('Analytics');

        $this->assertNotNull($module);
        $this->assertTrue($module->enabled);
        $this->assertSame('analytics', $module->slug);
    }
}
