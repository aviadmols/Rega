<?php

namespace App\Modules\Admin\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Filament\Operator\Resources\Users\Pages\ListUsers;
use App\Modules\Admin\Http\Middleware\EnterOperatorScope;
use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\CurrentShop;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The operator panel works inside one shop at a time. The choice lives in the session and is put
 * into the tenant scope for the whole request, so a screen that never thought about tenancy is
 * still showing one store.
 */
final class OperatorShopContextTest extends TestCase
{
    use RefreshDatabase;

    private Shop $first;

    private Shop $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->first = Shop::factory()->create(['name' => 'חנות ראשונה']);
        $this->second = Shop::factory()->create(['name' => 'חנות שנייה']);

        $this->actingAs(User::factory()->operator()->create());
        Filament::setCurrentPanel(Filament::getPanel(User::OPERATOR_PANEL));
    }

    public function test_the_choice_is_kept_and_becomes_the_scope_for_the_whole_request(): void
    {
        // Nothing picked: the panel looks across the platform, as it always could.
        $this->assertNull(CurrentShop::id());
        $this->assertNull($this->scopeDuringRequest());

        $this->post('/admin/shop', ['shop' => $this->first->id, 'back' => '/operator/configuration'])
            ->assertRedirect('/operator/configuration');

        $this->assertSame($this->first->id, CurrentShop::id(), 'and it survives the next page');
        $this->assertSame($this->first->id, $this->scopeDuringRequest());

        $this->post('/admin/shop', ['shop' => $this->second->id]);
        $this->assertSame($this->second->id, $this->scopeDuringRequest());

        // And back to everything, on purpose.
        $this->post('/admin/shop', ['shop' => CurrentShop::EVERY]);
        $this->assertNull(CurrentShop::id());
        $this->assertNull($this->scopeDuringRequest());
    }

    /** What the panel's middleware puts into the tenant scope for a request right now. */
    private function scopeDuringRequest(): ?string
    {
        $seen = null;

        app(EnterOperatorScope::class)->handle(
            Request::create('/operator/configuration'),
            function () use (&$seen): Response {
                $seen = app(TenantContext::class)->id();

                return new Response;
            },
        );

        return $seen;
    }

    public function test_what_belongs_to_no_shop_is_untouched_by_the_choice(): void
    {
        $this->post('/admin/shop', ['shop' => $this->first->id]);

        // Users are the platform's, not a shop's, so choosing a shop hides none of them.
        $other = User::factory()->create(['name' => 'מישהו אחר']);
        Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$other]);
    }

    public function test_a_shop_that_is_gone_leaves_the_panel_looking_at_everything(): void
    {
        $this->post('/admin/shop', ['shop' => $this->first->id]);
        $this->first->delete();

        $this->get('/operator/configuration')->assertOk();
        $this->assertNull(CurrentShop::id(), 'the choice is dropped rather than breaking every screen');
    }

    public function test_only_an_operator_may_change_the_shop(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/admin/shop', ['shop' => $this->second->id])->assertForbidden();
        $this->assertNull(CurrentShop::id());
    }

    public function test_the_picker_is_in_the_top_bar_with_every_shop_to_choose_from(): void
    {
        $page = $this->get('/operator/configuration')->assertOk();

        $page->assertSee('data-operator-shop', false);
        $page->assertSee(__('admin::panels.shop.every'));
        $page->assertSee('חנות ראשונה');
        $page->assertSee('חנות שנייה');
    }

    public function test_a_platform_with_one_shop_is_already_inside_it(): void
    {
        // Two shops: nothing is assumed, and the panel starts across both.
        $this->assertNull($this->scopeDuringRequest());

        $this->second->delete();

        // One shop left: choosing it would be the only sensible thing to do, so it is done.
        $this->assertSame($this->first->id, $this->scopeDuringRequest());
        $this->assertSame($this->first->id, CurrentShop::effective(), 'and the picker says so');
    }

    public function test_asking_for_every_shop_is_an_answer_and_is_not_undone(): void
    {
        // With one shop, the panel helps itself in. Stepping out must then stick.
        $this->second->delete();
        $this->assertSame($this->first->id, $this->scopeDuringRequest());

        $this->post('/admin/shop', ['shop' => CurrentShop::EVERY]);

        $this->assertNull(CurrentShop::id());
        $this->assertNull($this->scopeDuringRequest(), 'and the only shop does not pull it back in');
        $this->assertNull($this->scopeDuringRequest(), 'on the next page either');
    }

    public function test_a_shops_own_settings_come_first_and_the_platform_tuning_is_folded_away(): void
    {
        $this->post('/admin/shop', ['shop' => $this->first->id]);

        $page = $this->get('/operator/configuration')->assertOk();

        // What running a store is about, in its own groups.
        $page->assertSee(__('admin::configuration.groups.shown.title'));
        $page->assertSee(__('admin::configuration.groups.whatsapp.title'));

        // Everything else is behind one heading rather than spread over the screen.
        $page->assertSee(__('admin::configuration.groups.advanced.title'));
        $page->assertSee(__('admin::configuration.groups.advanced.help'));
    }
}
