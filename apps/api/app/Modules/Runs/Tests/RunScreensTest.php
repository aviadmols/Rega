<?php

namespace App\Modules\Runs\Tests;

use App\Modules\Admin\Models\User;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Filament\Operator\Resources\Runs\Pages\ListRuns;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class RunScreensTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_operator_sees_runs_live_and_opens_one_in_both_languages(): void
    {
        $shop = Shop::factory()->create(['name' => 'Gueta Avigdor']);
        $run = app(RecordsRuns::class)->track(
            agent: 'connections.store_checker',
            action: 'connections.test',
            shopId: $shop->id,
            input: ['site_url' => 'https://guetaavigdor.test'],
            work: fn (RunContext $r) => $r->output(['http_status' => 200])->summary('connections::runs.connected', ['version' => '0.1.0', 'products' => '1,191']),
        );

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(ListRuns::class)
            ->assertCanSeeTableRecords([$run])
            ->assertSee('Gueta Avigdor');

        foreach (['he', 'en'] as $locale) {
            $this->withHeader('Accept-Language', $locale)
                ->get("/operator/runs/{$run->id}")
                ->assertOk()
                ->assertSee(__('connections::agents.store_checker', [], $locale))
                ->assertSee(__('connections::runs.connected', ['version' => '0.1.0', 'products' => '1,191'], $locale))
                ->assertSee('guetaavigdor.test');
        }
    }

    public function test_merchants_cannot_open_the_activity_log(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/operator/runs')
            ->assertForbidden();
    }
}
