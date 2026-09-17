<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Modules\ModuleRepository;
use Tests\TestCase;

final class EnrichmentModuleTest extends TestCase
{
    public function test_module_is_discovered_and_enabled(): void
    {
        $module = app(ModuleRepository::class)->get('Enrichment');

        $this->assertNotNull($module);
        $this->assertTrue($module->enabled);
        $this->assertSame('enrichment', $module->slug);
    }
}
