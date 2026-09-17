<?php

namespace App\Modules\Admin\Tests;

use App\Modules\Admin\Http\Middleware\ApplyAdminLocale;
use App\Modules\Admin\Http\Middleware\EnterOperatorScope;
use App\Modules\Admin\Http\Middleware\SyncTenantFromPanel;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pressing a button or saving a form in a panel is a Livewire request to /livewire/update, which
 * does not pass through the panel's route middleware. Only middleware registered as persistent is
 * replayed there. These are the ones a button press depends on.
 */
final class AdminPanelMiddlewareTest extends TestCase
{
    public function test_button_presses_keep_the_language_cross_shop_access_and_the_current_shop(): void
    {
        // Resolving the panels registers their persistent middleware with Livewire.
        Filament::getPanel('operator');
        Filament::getPanel('merchant');

        $persistent = Livewire::getPersistentMiddleware();

        $this->assertContains(ApplyAdminLocale::class, $persistent);
        $this->assertContains(EnterOperatorScope::class, $persistent);
        $this->assertContains(SyncTenantFromPanel::class, $persistent);
    }
}
