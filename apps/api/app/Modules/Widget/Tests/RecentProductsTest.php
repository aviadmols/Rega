<?php

namespace App\Modules\Widget\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** The circle that shows a shopper the products they themselves looked at. */
final class RecentProductsTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private const TOKEN = 'rgt_cccccccccccccccccccccccccccccccccccccccccccccccc';

    private const ORIGIN = 'https://store.test';

    private const VISITOR = 'anon-rrrrrrrrrrrrrrrrrrrr';

    private string $site;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => self::ORIGIN, 'access_token' => self::TOKEN,
        ]));
        $this->site = SiteKeys::site(self::TOKEN);

        $shelf = $this->category('30', 'מדפים');
        foreach (['10' => 'מדף אורן', '11' => 'תומך מדף', '12' => 'ברגים', '13' => 'צבע לבן'] as $id => $title) {
            $this->product($id, $title, 'x', [$shelf], ['url' => "https://store.test/product/{$id}/"]);
        }
    }

    public function test_the_shopper_sees_the_products_they_opened_most_without_the_one_they_are_on(): void
    {
        $this->assertSame([], $this->recent()->json('data.products'), 'nothing viewed yet');

        $this->viewed('11', 4);
        $this->viewed('12', 2);
        $this->viewed('13', 1);
        $this->viewed('10', 9);
        // The team's preview visits and anything older than the window are not their browsing.
        $this->viewed('13', 20, preview: true);
        $this->viewed('12', 20, at: now()->subDays(30));

        $products = $this->recent(on: '10')->json('data.products');

        $this->assertSame(['11', '12', '13'], array_column($products, 'id'), 'most visits first, without the product they are on');
        $this->assertSame('4 צפיות', $products[0]['reason']);
        $this->assertSame('צפייה אחת', $products[2]['reason']);
        $this->assertSame('תומך מדף', $products[0]['title']);
        $this->assertSame('https://store.test/product/11/', $products[0]['url']);
        $this->assertNull($this->recent(on: '10')->json('data.signed_up'), 'they left no contact');

        $this->assertSame(['10', '11', '12', '13'], array_column($this->recent()->json('data.products'), 'id'), 'on an article, all of them');

        Settings::set('shoppers.recent_products_count', 2, $this->shop->id);
        Cache::flush();
        $this->assertCount(2, $this->recent()->json('data.products'));
    }

    public function test_the_page_bank_says_whether_to_ask_and_the_endpoint_is_refused_when_the_shop_is_off(): void
    {
        $bank = $this->get("/api/v1/widget/{$this->site}/page?type=product&id=10&locale=he")->json();
        $this->assertTrue($bank['recent']);
        $this->assertNull($bank['signup'], 'the sign-up is off until a shop turns it on');

        Features::override('shoppers.signup', true, $this->shop->id);
        Settings::set('shoppers.signup_title', 'לשמור לכם את הרשימה?', $this->shop->id);
        Cache::flush();

        $signup = $this->get("/api/v1/widget/{$this->site}/page?type=product&id=10&locale=he")->json('signup');
        $this->assertSame('לשמור לכם את הרשימה?', $signup['title']);
        $this->assertStringContainsString('מאשר', $signup['consent'], 'the built-in consent wording when the shop wrote none');

        $this->call('POST', "/api/v1/widget/{$this->site}/recent", server: ['HTTP_ORIGIN' => 'https://other.test', 'CONTENT_TYPE' => 'text/plain'], content: '{"vid":"'.self::VISITOR.'"}')
            ->assertForbidden();

        Features::override('shoppers.recent_products', false, $this->shop->id);
        Cache::flush();
        $this->recent()->assertForbidden();
        $this->assertFalse($this->get("/api/v1/widget/{$this->site}/page?type=product&id=10&locale=he")->json('recent'));
    }

    private function recent(string $on = ''): TestResponse
    {
        return $this->call(
            'POST',
            "/api/v1/widget/{$this->site}/recent",
            server: ['HTTP_ORIGIN' => self::ORIGIN, 'CONTENT_TYPE' => 'text/plain'],
            content: json_encode(['vid' => self::VISITOR, 'id' => $on, 'locale' => 'he'], JSON_THROW_ON_ERROR),
        );
    }

    private function viewed(string $product, int $times, bool $preview = false, mixed $at = null): void
    {
        app(TenantContext::class)->run($this->shop->id, function () use ($product, $times, $preview, $at): void {
            foreach (range(1, $times) as $i) {
                AnalyticsEvent::query()->create([
                    'shop_id' => $this->shop->id, 'event_id' => 'r'.++$this->sequence, 'type' => 'page_view',
                    'page_type' => 'product', 'page_path' => '/product/'.$product, 'product_external_id' => $product,
                    'visitor_hash' => hash('sha256', $this->shop->id.'|'.self::VISITOR), 'session_id' => 's1',
                    'preview' => $preview, 'occurred_at' => $at ?? now()->subMinutes($i),
                ]);
            }
        });
    }
}
