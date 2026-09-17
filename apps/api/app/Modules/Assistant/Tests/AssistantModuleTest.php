<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Modules\ModuleRepository;
use Tests\TestCase;

final class AssistantModuleTest extends TestCase
{
    public function test_module_is_discovered_and_enabled(): void
    {
        $module = app(ModuleRepository::class)->get('Assistant');

        $this->assertNotNull($module);
        $this->assertTrue($module->enabled);
        $this->assertSame('assistant', $module->slug);
    }
}
