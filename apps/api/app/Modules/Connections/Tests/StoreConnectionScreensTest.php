<?php

namespace App\Modules\Connections\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Connections\Filament\Operator\Resources\StoreConnections\Pages\CreateStoreConnection;
use App\Modules\Connections\Filament\Operator\Resources\StoreConnections\Pages\EditStoreConnection;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

final class StoreConnectionScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());

        // Livewire::test calls components directly, without the operator panel's middleware
        // that puts requests into cross-shop mode. AdminPanelMiddlewareTest covers that the
        // middleware also runs on real Livewire requests.
        app(TenantContext::class)->enterUnscoped();

        Http::fake(['store.test/*' => Http::response(['data' => [
            'plugin' => ['version' => '0.1.0'],
            'site' => ['wordpress' => '7.1'],
            'woocommerce' => ['version' => '11.1.0'],
            'counts' => ['products' => ['publish' => 25]],
        ]])]);
    }

    public function test_the_operator_adds_a_connection_and_it_is_tested_at_once(): void
    {
        $shop = Shop::factory()->create();

        Livewire::test(CreateStoreConnection::class)
            ->fillForm([
                'shop_id' => $shop->id,
                'platform' => 'woocommerce',
                'site_url' => 'https://store.test',
                'access_token' => 'rgt_'.str_repeat('a', 48),
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__('connections::connections.notifications.connected'));

        $connection = app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->sole());
        $this->assertSame(ConnectionStatus::Connected, $connection->status);
        $this->assertDatabaseHas('runs', ['agent' => 'connections.store_checker', 'shop_id' => $shop->id, 'status' => 'succeeded']);
    }

    public function test_editing_without_a_token_keeps_the_saved_one(): void
    {
        $shop = Shop::factory()->create();
        $token = 'rgt_'.str_repeat('b', 48);
        $connection = app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $shop->id, 'site_url' => 'https://store.test', 'access_token' => $token,
        ]));

        Livewire::test(EditStoreConnection::class, ['record' => $connection->getKey()])
            ->assertSee(__('connections::connections.fields.access_token_keep'))
            ->fillForm(['access_token' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($token, $connection->fresh()->access_token);
    }

    public function test_the_list_and_edit_screens_render_in_both_languages(): void
    {
        $shop = Shop::factory()->create(['name' => 'Gueta Avigdor']);
        $connection = app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $shop->id, 'site_url' => 'https://store.test', 'access_token' => 'rgt_'.str_repeat('c', 48),
        ]));

        foreach (['he', 'en'] as $locale) {
            $this->withHeader('Accept-Language', $locale)->get('/operator/store-connections')
                ->assertOk()
                ->assertSee('Gueta Avigdor')
                ->assertSee(__('connections::connections.plural', [], $locale));

            $this->withHeader('Accept-Language', $locale)->get("/operator/store-connections/{$connection->id}/edit")
                ->assertOk()
                ->assertSee(__('connections::connections.actions.test', [], $locale))
                ->assertDontSee('rgt_ccccccccccccccc');
        }
    }
}
