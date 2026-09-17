<?php

namespace App\Modules\Ai\Tests;

use App\Core\Modules\ModuleRepository;
use Tests\TestCase;

final class AiModuleTest extends TestCase
{
    public function test_module_is_discovered_and_enabled(): void
    {
        $module = app(ModuleRepository::class)->get('Ai');

        $this->assertNotNull($module);
        $this->assertTrue($module->enabled);
        $this->assertSame('ai', $module->slug);
    }
}
