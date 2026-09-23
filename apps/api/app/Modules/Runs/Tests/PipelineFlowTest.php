<?php

namespace App\Modules\Runs\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Filament\Operator\Pages\PipelineFlow;
use App\Modules\Runs\Models\Run;
use App\Modules\Runs\Support\Pipeline;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The shape of the platform, drawn from the runs it produced. It is the whole platform, not one
 * shop, and a step nobody has run yet is still on it.
 */
final class PipelineFlowTest extends TestCase
{
    use RefreshDatabase;

    private Shop $first;

    private Shop $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->first = Shop::factory()->create(['name' => 'חנות ראשונה']);
        $this->second = Shop::factory()->create(['name' => 'חנות שנייה']);

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
    }

    public function test_every_step_is_drawn_whether_or_not_it_ever_ran(): void
    {
        $steps = collect(Livewire::test(PipelineFlow::class)->instance()->stages())
            ->pluck('steps')
            ->flatten(1)
            ->keyBy('action');

        $this->assertSame(Pipeline::actions(), $steps->keys()->all(), 'in the order the work happens');
        $this->assertSame(0, $steps['catalog.sync']['runs']);
        $this->assertNull($steps['catalog.sync']['last'], 'nothing pretends to have happened');

        // Which steps involve a model is on the screen, and most do not.
        $this->assertNull($steps['enrichment.read_in_code']['model'], 'code only');
        $this->assertNotNull($steps['assistant.answer']['model']);
        $this->assertTrue($steps['enrichment.import_results']['outside'], 'that model runs away from the platform');
        $this->assertSame('02:30', $steps['catalog.sync']['clock']);
    }

    public function test_it_counts_across_every_shop_and_names_the_model_a_run_used(): void
    {
        $this->logRun($this->first, 'catalog.sync', cost: 0);
        $this->logRun($this->second, 'catalog.sync', cost: 0);
        $this->logRun($this->first, 'assistant.answer', cost: 0.0031, model: 'gpt-5.4-mini');
        $this->logRun($this->first, 'assistant.answer', cost: 0.0004, model: 'gpt-5.4-nano', status: RunStatus::Failed);

        $page = Livewire::test(PipelineFlow::class);
        $steps = collect($page->instance()->stages())->pluck('steps')->flatten(1)->keyBy('action');

        $this->assertSame(2, $steps['catalog.sync']['runs'], 'both shops, on one screen');
        $this->assertSame(2, $steps['assistant.answer']['runs']);
        $this->assertSame(1, $steps['assistant.answer']['failed']);
        $this->assertEqualsWithDelta(0.0035, $steps['assistant.answer']['cost'], 0.00001);

        // The models the runs really used, not the ones the settings hoped for.
        $this->assertStringContainsString('gpt-5.4-mini', $steps['assistant.answer']['model']);
        $this->assertStringContainsString('gpt-5.4-nano', $steps['assistant.answer']['model']);

        // And the list of what happened names the shop each run was for.
        $shops = array_column($page->instance()->recent(), 'shop');
        $this->assertContains('חנות ראשונה', $shops);
        $this->assertContains('חנות שנייה', $shops);
    }

    public function test_the_window_changes_what_is_counted(): void
    {
        $this->logRun($this->first, 'catalog.sync', cost: 0, at: now()->subDays(40));

        $page = Livewire::test(PipelineFlow::class);
        $count = fn (): int => collect($page->instance()->stages())->pluck('steps')->flatten(1)->keyBy('action')['catalog.sync']['runs'];

        $this->assertSame(0, $count(), 'outside the month');

        $page->call('setDays', 90);
        $this->assertSame(1, $count());

        // A window nobody offered is not honoured.
        $page->call('setDays', 5);
        $this->assertSame(30, $page->instance()->days);
    }

    public function test_the_screen_is_the_platforms_and_opens_whatever_shop_is_chosen(): void
    {
        $this->logRun($this->second, 'catalog.sync', cost: 0);

        // Inside one shop the screen still answers for the platform, because that is its subject.
        app(TenantContext::class)->run($this->first->id, function (): void {
            $steps = collect(Livewire::test(PipelineFlow::class)->instance()->stages())->pluck('steps')->flatten(1)->keyBy('action');

            $this->assertSame(1, $steps['catalog.sync']['runs'], "another shop's run is still counted");
        });
    }

    private function logRun(Shop $shop, string $action, float $cost, ?string $model = null, RunStatus $status = RunStatus::Succeeded, $at = null): void
    {
        app(TenantContext::class)->runUnscoped(fn () => Run::query()->create([
            'shop_id' => $shop->id,
            'agent' => 'test',
            'action' => $action,
            'status' => $status,
            'trigger' => RunTrigger::Manual,
            'model' => $model,
            'cost_usd' => $cost,
            'duration_ms' => 1200,
            'started_at' => $at ?? now(),
            'finished_at' => $at ?? now(),
        ]));
    }
}
