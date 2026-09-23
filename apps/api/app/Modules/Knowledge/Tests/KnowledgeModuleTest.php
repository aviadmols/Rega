<?php

namespace App\Modules\Knowledge\Tests;

use App\Core\Modules\ModuleRepository;
use Tests\TestCase;

final class KnowledgeModuleTest extends TestCase
{
    public function test_module_is_discovered_and_enabled(): void
    {
        $module = app(ModuleRepository::class)->get('Knowledge');

        $this->assertNotNull($module);
        $this->assertTrue($module->enabled);
        $this->assertSame('knowledge', $module->slug);
    }
}
