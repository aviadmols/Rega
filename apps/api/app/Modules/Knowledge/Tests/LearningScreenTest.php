<?php

namespace App\Modules\Knowledge\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Knowledge\Actions\TakeKnowledgeSnapshot;
use App\Modules\Knowledge\Filament\Operator\Pages\Learning;
use App\Modules\Tenancy\Enums\Vertical;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The screen says which stage is running, and when one is not, the exact thing it waits for.
 */
final class LearningScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
    }

    public function test_it_names_the_stage_that_is_waiting_and_what_it_waits_for(): void
    {
        Shop::factory()->create(['vertical' => Vertical::HardwareStore]);

        $stages = collect(Livewire::test(Learning::class)->instance()->stages())->keyBy('key');

        $this->assertSame('running', $stages['reading']['state'], 'the nightly reading is on from day one');
        $this->assertSame('running', $stages['audit']['state']);

        // One shop cannot teach a trade, and the screen says so with the number.
        $this->assertSame('waiting', $stages['promotion']['state']);
        $this->assertStringContainsString('1', $stages['promotion']['detail'], 'how many shops there are');

        // And what has not been built says so rather than pretending to wait.
        $this->assertSame('planned', $stages['questions']['state']);
        $this->assertSame('planned', $stages['session']['state']);
    }

    public function test_it_shows_what_a_shop_gained_only_once_there_is_a_week_to_compare(): void
    {
        $shop = Shop::factory()->create(['vertical' => Vertical::HardwareStore]);
        app(TakeKnowledgeSnapshot::class)->handle($shop);

        $week = Livewire::test(Learning::class)->instance()->thisWeek();

        $this->assertCount(1, $week);
        $this->assertNull($week[0]['gained'], 'one snapshot is not a week');
        $this->assertSame('none', $week[0]['optimising_for'], 'and nothing is being learned from yet');
    }

    public function test_a_trade_that_has_learned_nothing_says_nothing(): void
    {
        $trades = collect(Livewire::test(Learning::class)->instance()->trades())
            ->firstWhere('vertical', Vertical::HardwareStore);

        $this->assertNull($trades['version']);
        $this->assertSame(0, $trades['words']);
        $this->assertSame([], $trades['priors']);
    }

    public function test_the_screen_is_about_the_system_so_it_does_not_need_a_shop_chosen(): void
    {
        app(TenantContext::class)->clear();

        $this->assertTrue(Learning::canAccess());
        Livewire::test(Learning::class)->assertOk();
    }
}
