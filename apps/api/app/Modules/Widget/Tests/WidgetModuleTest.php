<?php

namespace App\Modules\Widget\Tests;

use App\Core\Modules\ModuleRepository;
use Tests\TestCase;

final class WidgetModuleTest extends TestCase
{
    public function test_module_is_discovered_and_enabled(): void
    {
        $module = app(ModuleRepository::class)->get('Widget');

        $this->assertNotNull($module);
        $this->assertTrue($module->enabled);
        $this->assertSame('widget', $module->slug);
    }
}
