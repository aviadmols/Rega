<?php

namespace App\Modules\Tenancy\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Tenancy\Actions\IssueApiKey;
use App\Modules\Tenancy\Actions\RevokeApiKey;
use App\Modules\Tenancy\Models\Shop;
use App\Modules\Tenancy\Support\IssuedApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ShopApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private IssuedApiKey $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create(['name' => 'Tools IL', 'domain' => 'tools.co.il']);
        $this->key = app(IssueApiKey::class)->handle($this->shop, 'plugin');
    }

    public function test_a_valid_key_identifies_the_shop(): void
    {
        $this->getJson('/api/v1/shop', ['X-Shop-Key' => $this->key->plaintext])
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $this->shop->id,
                'name' => 'Tools IL',
                'slug' => $this->shop->slug,
                'platform' => 'woocommerce',
                'domain' => 'tools.co.il',
                'content_locale' => 'he',
                'direction' => 'rtl',
                'currency' => 'ILS',
                'timezone' => 'Asia/Jerusalem',
                'status' => 'active',
            ]]);

        $this->assertNotNull($this->key->key->fresh()->last_used_at);
    }

    public function test_a_bearer_token_works_too(): void
    {
        $this->getJson('/api/v1/shop', ['Authorization' => 'Bearer '.$this->key->plaintext])->assertOk();
    }

    public function test_the_request_runs_in_the_shops_tenant_context(): void
    {
        Route::middleware(['api', 'shop.key'])->get('/api/v1/_probe', fn (TenantContext $tenant) => ['shop' => $tenant->id()]);

        $this->getJson('/api/v1/_probe', ['X-Shop-Key' => $this->key->plaintext])
            ->assertOk()
            ->assertExactJson(['shop' => $this->shop->id]);
    }

    public function test_missing_invalid_and_revoked_keys_are_rejected_with_stable_codes(): void
    {
        $this->getJson('/api/v1/shop')->assertUnauthorized()->assertExactJson(['error' => 'missing_key']);

        $this->getJson('/api/v1/shop', ['X-Shop-Key' => 'usk_nope_nope'])->assertUnauthorized()->assertExactJson(['error' => 'invalid_key']);
        $this->getJson('/api/v1/shop', ['X-Shop-Key' => 'not-even-a-key'])->assertUnauthorized()->assertExactJson(['error' => 'invalid_key']);

        app(RevokeApiKey::class)->handle($this->key->key);
        $this->getJson('/api/v1/shop', ['X-Shop-Key' => $this->key->plaintext])->assertUnauthorized()->assertExactJson(['error' => 'invalid_key']);
    }

    public function test_a_paused_shop_is_refused(): void
    {
        $this->shop->update(['status' => 'paused']);

        $this->getJson('/api/v1/shop', ['X-Shop-Key' => $this->key->plaintext])
            ->assertForbidden()
            ->assertExactJson(['error' => 'shop_inactive']);
    }

    public function test_the_api_access_flag_is_a_kill_switch_for_one_shop(): void
    {
        $other = Shop::factory()->create();
        $otherKey = app(IssueApiKey::class)->handle($other, 'plugin');

        Features::override('tenancy.api_access', false, $this->shop->id);

        $this->getJson('/api/v1/shop', ['X-Shop-Key' => $this->key->plaintext])
            ->assertForbidden()
            ->assertExactJson(['error' => 'api_disabled']);

        $this->getJson('/api/v1/shop', ['X-Shop-Key' => $otherKey->plaintext])->assertOk();
    }

    public function test_the_rate_limit_comes_from_the_shop_setting(): void
    {
        // 65, not 60: the anonymous per-IP fallback is 60, so passing 65 proves the key's
        // own limit is the one in force.
        Settings::set('tenancy.api_requests_per_minute', 65, $this->shop->id);

        for ($i = 0; $i < 65; $i++) {
            $this->getJson('/api/v1/shop', ['X-Shop-Key' => $this->key->plaintext])->assertOk();
        }

        $this->getJson('/api/v1/shop', ['X-Shop-Key' => $this->key->plaintext])
            ->assertStatus(429)
            ->assertExactJson(['error' => 'rate_limited']);
    }
}
