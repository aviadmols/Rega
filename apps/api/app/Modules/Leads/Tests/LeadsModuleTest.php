<?php

namespace App\Modules\Leads\Tests;

use App\Core\Modules\ModuleRepository;
use Tests\TestCase;

final class LeadsModuleTest extends TestCase
{
    public function test_module_is_discovered_and_enabled(): void
    {
        $module = app(ModuleRepository::class)->get('Leads');

        $this->assertNotNull($module);
        $this->assertTrue($module->enabled);
        $this->assertSame('leads', $module->slug);
    }
}
