<?php

namespace App\Modules\Leads\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Leads\Enums\LeadGoal;
use App\Modules\Leads\Filament\Operator\Pages\LeadFlowSetup;
use App\Modules\Leads\Models\LeadFlow;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The one decision the platform cannot make for a shop, asked once and then obeyed.
 */
final class LeadFlowSetupTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create();
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        app(TenantContext::class)->set($this->shop->id);
    }

    public function test_a_shop_says_what_it_offers_and_what_it_asks_for(): void
    {
        Livewire::test(LeadFlowSetup::class)
            ->fillForm([
                'goal' => LeadGoal::Advice->value,
                'offer' => 'שיחת ייעוץ של 15 דקות, בלי עלות',
                'promise' => 'נחזור אליכם תוך יום עסקים',
                'consent' => 'אני מאשר/ת שהפרטים יישמרו ושתפנו אליי.',
                'fields' => [
                    ['type' => 'phone', 'key' => 'phone', 'label' => 'טלפון', 'required' => true],
                    ['type' => 'name', 'key' => 'name', 'label' => 'שם', 'required' => false],
                ],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $flow = app(TenantContext::class)->run($this->shop->id, fn () => LeadFlow::inForce($this->shop->id));

        $this->assertNotNull($flow);
        $this->assertSame(LeadGoal::Advice, $flow->goal);
        $this->assertSame(1, $flow->version);
        $this->assertTrue($flow->canReachAnyone(), 'a required phone is a way to reach somebody');
        $this->assertCount(2, $flow->asked());
    }

    public function test_saving_again_adds_a_version_rather_than_editing_the_old_one(): void
    {
        $this->saveOnce('הצעה ראשונה');
        $this->saveOnce('הצעה שנייה');

        $all = app(TenantContext::class)->run($this->shop->id, fn () => LeadFlow::query()->orderBy('version')->get());

        $this->assertCount(2, $all, 'the wording somebody agreed to is never overwritten');
        $this->assertFalse($all[0]->active);
        $this->assertTrue($all[1]->active);
        $this->assertSame('הצעה שנייה', LeadFlow::inForce($this->shop->id)->offer);
    }

    public function test_a_flow_nobody_can_be_reached_through_is_saved_but_called_out(): void
    {
        Livewire::test(LeadFlowSetup::class)
            ->fillForm([
                'goal' => LeadGoal::Download->value,
                'offer' => 'מדריך להורדה',
                'promise' => null,
                'consent' => 'אני מאשר/ת.',
                // Only a name: plenty of typing, nobody to call.
                'fields' => [['type' => 'name', 'key' => 'name', 'label' => 'שם', 'required' => true]],
            ])
            ->call('save')
            ->assertNotified();

        $flow = app(TenantContext::class)->run($this->shop->id, fn () => LeadFlow::inForce($this->shop->id));

        $this->assertFalse($flow->canReachAnyone());
    }

    public function test_a_field_of_a_kind_nobody_defined_is_ignored_rather_than_shown(): void
    {
        $flow = app(TenantContext::class)->run($this->shop->id, fn () => LeadFlow::query()->create([
            'shop_id' => $this->shop->id, 'version' => 1, 'goal' => LeadGoal::Quote,
            'offer' => 'x', 'consent' => 'y', 'active' => true,
            'fields' => [
                ['type' => 'email', 'key' => 'email', 'label' => 'מייל', 'required' => true],
                ['type' => 'creditcard', 'key' => 'card', 'label' => 'כרטיס', 'required' => true],
                ['nonsense' => true],
            ],
        ]));

        $this->assertSame(['email'], array_column($flow->asked(), 'key'), 'only the kinds the flow knows');
    }

    private function saveOnce(string $offer): void
    {
        Livewire::test(LeadFlowSetup::class)
            ->fillForm([
                'goal' => LeadGoal::Advice->value,
                'offer' => $offer,
                'promise' => null,
                'consent' => 'אני מאשר/ת.',
                'fields' => [['type' => 'phone', 'key' => 'phone', 'label' => 'טלפון', 'required' => true]],
            ])
            ->call('save');
    }
}
