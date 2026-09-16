<?php

namespace App\Modules\Tenancy\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Tenancy\Actions\CreateShop;
use App\Modules\Tenancy\Actions\IssueApiKey;
use App\Modules\Tenancy\Actions\RevokeApiKey;
use App\Modules\Tenancy\Exceptions\ApiKeyLimitReached;
use App\Modules\Tenancy\Models\Shop;
use App\Modules\Tenancy\Models\ShopApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ShopAndApiKeyActionsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function domains(): array
    {
        return [
            'bare' => ['store.co.il', 'store.co.il'],
            'www and scheme' => ['https://www.Store.co.il/', 'store.co.il'],
            'with a path' => ['http://store.co.il/shop/cart', 'store.co.il'],
            'trailing dot and spaces' => ['  store.co.il. ', 'store.co.il'],
            'subdomain kept' => ['shop.store.co.il', 'shop.store.co.il'],
        ];
    }

    #[DataProvider('domains')]
    public function test_domains_are_normalized(string $input, string $expected): void
    {
        $this->assertSame($expected, CreateShop::normalizeDomain($input));
    }

    public function test_creating_a_shop_builds_a_unique_slug_from_the_name(): void
    {
        $first = app(CreateShop::class)->handle(['name' => 'Tools IL', 'domain' => 'tools.co.il', 'platform' => 'woocommerce']);
        $second = app(CreateShop::class)->handle(['name' => 'Tools IL', 'domain' => 'tools2.co.il', 'platform' => 'woocommerce']);

        $this->assertSame('tools-il', $first->slug);
        $this->assertStringStartsWith('tools-il-', $second->slug);
        $this->assertSame('he', $first->content_locale);
        $this->assertSame('rtl', $first->contentDirection());
        $this->assertTrue($first->isActive());
    }

    public function test_a_hebrew_shop_name_takes_its_slug_from_the_domain(): void
    {
        $shop = app(CreateShop::class)->handle(['name' => 'כלי עבודה דמו', 'domain' => 'https://www.demo-tools.co.il', 'platform' => 'woocommerce']);

        $this->assertSame('demo-tools', $shop->slug);
    }

    public function test_an_issued_key_is_shown_once_and_stored_only_as_a_hash(): void
    {
        $shop = Shop::factory()->create();

        $issued = app(IssueApiKey::class)->handle($shop, 'Main site');

        $this->assertMatchesRegularExpression('/^usk_[a-z0-9]{8}_[A-Za-z0-9]{40}$/', $issued->plaintext);
        $this->assertStringStartsWith($issued->key->prefix.'_', $issued->plaintext);
        $this->assertDatabaseMissing('shop_api_keys', ['hash' => $issued->plaintext]);
        $this->assertDatabaseHas('shop_api_keys', ['hash' => hash('sha256', $issued->plaintext)]);
        $this->assertTrue(ShopApiKey::findActiveByPlaintext($issued->plaintext)?->is($issued->key));
    }

    public function test_the_active_key_cap_comes_from_the_shop_setting(): void
    {
        $shop = Shop::factory()->create();
        Settings::set('tenancy.max_active_api_keys', 2, $shop->id);

        $issue = app(IssueApiKey::class);
        $first = $issue->handle($shop, 'one');
        $issue->handle($shop, 'two');

        try {
            $issue->handle($shop, 'three');
            $this->fail('Expected ApiKeyLimitReached.');
        } catch (ApiKeyLimitReached $e) {
            $this->assertSame(2, $e->limit);
        }

        // Revoking frees a slot.
        app(RevokeApiKey::class)->handle($first->key);
        $issue->handle($shop, 'three');

        $this->assertSame(2, $shop->apiKeys()->active()->count());
        $this->assertNull(ShopApiKey::findActiveByPlaintext($first->plaintext));
    }

    public function test_deleting_a_shop_removes_its_keys_and_its_overrides(): void
    {
        $shop = Shop::factory()->create();
        app(IssueApiKey::class)->handle($shop, 'key');
        Features::override('tenancy.api_access', false, $shop->id);
        Settings::set('tenancy.max_active_api_keys', 5, $shop->id);

        $shop->delete();

        $this->assertDatabaseCount('shop_api_keys', 0);
        $this->assertDatabaseCount('feature_overrides', 0);
        $this->assertDatabaseCount('setting_overrides', 0);
    }
}
