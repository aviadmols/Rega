<?php

namespace App\Modules\Connections\Tests;

use App\Core\Modules\ModuleRepository;
use Tests\TestCase;

final class ConnectionsModuleTest extends TestCase
{
    public function test_module_is_discovered_and_enabled(): void
    {
        $module = app(ModuleRepository::class)->get('Connections');

        $this->assertNotNull($module);
        $this->assertTrue($module->enabled);
        $this->assertSame('connections', $module->slug);
    }
}
