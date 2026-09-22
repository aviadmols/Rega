<?php

namespace App\Modules\Shoppers\Tests;

use App\Core\Modules\ModuleRepository;
use Tests\TestCase;

final class ShoppersModuleTest extends TestCase
{
    public function test_module_is_discovered_and_enabled(): void
    {
        $module = app(ModuleRepository::class)->get('Shoppers');

        $this->assertNotNull($module);
        $this->assertTrue($module->enabled);
        $this->assertSame('shoppers', $module->slug);
    }
}
