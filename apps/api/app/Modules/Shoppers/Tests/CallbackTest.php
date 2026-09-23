<?php

namespace App\Modules\Shoppers\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Shoppers\Models\ShopperCallback;
use App\Modules\Shoppers\Models\ShopperIdentity;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Leaving a way to be told one answer. It is not the shop's sign-up: it has a switch of its own,
 * it carries the question, and it is the team's job list rather than a mailing list.
 */
final class CallbackTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'rgt_dddddddddddddddddddddddddddddddddddddddddddddddd';

    private const VID = 'anon-visitor1234567890abcd';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN,
        ]));
    }

    public function test_a_question_nobody_answered_can_be_left_with_a_way_to_reach_them(): void
    {
        // The shop's own sign-up is off; this is a different thing and is not blocked by it.
        Features::override('shoppers.signup', false, $this->shop->id);

        $this->leave('0501234567', 'האם יש עוד צבעים למוצר?')->assertOk();

        $callback = app(TenantContext::class)->run($this->shop->id, fn () => ShopperCallback::query()->with('identity')->sole());

        $this->assertSame('האם יש עוד צבעים למוצר?', $callback->question);
        $this->assertSame('product', $callback->page_type);
        $this->assertSame('10', $callback->page_id);
        $this->assertNull($callback->answered_at, 'the team has not come back yet');

        // The contact is kept the way every contact is: encrypted, and shown only masked.
        $this->assertSame('phone', $callback->identity->channel);
        $this->assertStringContainsString('4567', $callback->identity->contact_masked);
        $this->assertStringNotContainsString('0501234567', (string) app(TenantContext::class)->run(
            $this->shop->id,
            fn () => ShopperIdentity::query()->sole()->getRawOriginal('contact'),
        ));
    }

    public function test_it_is_refused_when_the_shop_turned_it_off(): void
    {
        Features::override('shoppers.callbacks', false, $this->shop->id);

        $this->leave('0501234567', 'שאלה')->assertForbidden();
        $this->assertSame(0, app(TenantContext::class)->run($this->shop->id, fn (): int => ShopperCallback::query()->count()));
    }

    public function test_without_a_question_it_is_an_ordinary_sign_up(): void
    {
        Features::override('shoppers.signup', false, $this->shop->id);

        // No question means no callback, so the shop's own sign-up switch decides, and it is off.
        $this->leave('0501234567', '')->assertForbidden();
    }

    public function test_the_team_can_mark_one_as_handled(): void
    {
        $this->leave('0501234567', 'מתי זה חוזר למלאי?')->assertOk();

        app(TenantContext::class)->run($this->shop->id, function (): void {
            $callback = ShopperCallback::query()->sole();
            $callback->update(['answered_at' => now()]);

            $this->assertNotNull($callback->fresh()->answered_at);
        });
    }

    private function leave(string $contact, string $question): TestResponse
    {
        return $this->call('POST', '/api/v1/widget/'.SiteKeys::site(self::TOKEN).'/signup', server: [
            'HTTP_ORIGIN' => 'https://store.test', 'CONTENT_TYPE' => 'text/plain',
        ], content: json_encode([
            'vid' => self::VID,
            'contact' => $contact,
            'consent' => true,
            'locale' => 'he',
            'question' => $question,
            'type' => 'product',
            'id' => '10',
        ], JSON_UNESCAPED_UNICODE));
    }
}
