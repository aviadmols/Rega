<?php

namespace App\Modules\Leads\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Leads\Enums\LeadGoal;
use App\Modules\Leads\Filament\Operator\Pages\LeadList;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadFlow;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A phone number is the most personal thing this platform holds, so the screen treats it that way.
 */
final class LeadListTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create();
        $this->operator = User::factory()->operator()->create();
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs($this->operator);
        app(TenantContext::class)->set($this->shop->id);
    }

    public function test_a_number_is_masked_until_somebody_chooses_to_look_and_then_it_is_recorded(): void
    {
        $lead = $this->lead('972501234567', '********4567');

        $page = Livewire::test(LeadList::class);

        $this->assertSame('********4567', $page->instance()->contactOf($lead), 'masked by default');
        $this->assertNull($lead->fresh()->seen_by);

        $page->call('reveal', $lead->id);

        $this->assertSame('972501234567', $page->instance()->contactOf($lead->fresh()));

        $seen = $lead->fresh();
        $this->assertSame($this->operator->id, $seen->seen_by, 'who looked is kept');
        $this->assertNotNull($seen->seen_at);
    }

    public function test_the_first_look_is_the_one_that_is_remembered(): void
    {
        $lead = $this->lead('972501234567', '********4567');
        $page = Livewire::test(LeadList::class);

        $page->call('reveal', $lead->id);
        $first = $lead->fresh()->seen_at;

        $this->travel(2)->hours();
        $page->call('reveal', $lead->id);

        $this->assertEquals($first, $lead->fresh()->seen_at, 'the first time somebody looked, not the last');
    }

    public function test_the_list_shows_what_is_being_worked_on_and_counts_the_rest(): void
    {
        $this->lead('972501111111', '********1111');
        $handled = $this->lead('972502222222', '********2222');

        Livewire::test(LeadList::class)->call('mark', $handled->id, Lead::HANDLED);

        $page = Livewire::test(LeadList::class);

        $this->assertCount(1, $page->instance()->leads(), 'new only, by default');
        $this->assertSame(1, $page->instance()->counts()['new']);
        $this->assertSame(1, $page->instance()->counts()['handled']);

        $page->call('setShow', 'all');
        $this->assertCount(2, $page->instance()->leads());
    }

    public function test_a_status_nobody_defined_is_ignored(): void
    {
        $lead = $this->lead('972503333333', '********3333');

        Livewire::test(LeadList::class)->call('mark', $lead->id, 'sold_to_a_broker');

        $this->assertSame(Lead::NEW, $lead->fresh()->status);
    }

    public function test_leads_belong_to_a_shop_and_the_screen_is_hidden_without_one(): void
    {
        app(TenantContext::class)->clear();

        $this->assertFalse(LeadList::canAccess());
        $this->assertFalse(LeadList::shouldRegisterNavigation());
    }

    private function lead(string $contact, string $masked): Lead
    {
        return app(TenantContext::class)->run($this->shop->id, function () use ($contact, $masked): Lead {
            $flow = LeadFlow::query()->firstOrCreate(
                ['shop_id' => $this->shop->id, 'version' => 1],
                [
                    'goal' => LeadGoal::Advice, 'offer' => 'x', 'consent' => 'y', 'active' => true,
                    'fields' => [['type' => 'phone', 'key' => 'phone', 'label' => 'טלפון', 'required' => true]],
                ],
            );

            return Lead::query()->create([
                'shop_id' => $this->shop->id, 'flow_id' => $flow->id,
                'page_type' => 'product', 'page_external_id' => '10',
                'channel' => 'phone', 'contact_hash' => hash('sha256', $contact),
                'contact' => $contact, 'contact_masked' => $masked,
                'consented_at' => now(), 'consent_wording' => 'y',
                'quality' => 75, 'status' => Lead::NEW,
            ]);
        });
    }
}
