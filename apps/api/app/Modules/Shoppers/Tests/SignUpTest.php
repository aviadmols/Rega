<?php

namespace App\Modules\Shoppers\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Shoppers\Contracts\VisitHistory;
use App\Modules\Shoppers\Filament\Operator\Pages\ShopSignUps;
use App\Modules\Shoppers\Mail\VerificationCode;
use App\Modules\Shoppers\Models\ShopperIdentity;
use App\Modules\Shoppers\Models\ShopperVisitor;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/** Leaving a phone or an email, and what it takes before browsing follows a shopper. */
final class SignUpTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'rgt_ffffffffffffffffffffffffffffffffffffffffffffffff';

    private const ORIGIN = 'https://store.test';

    private Shop $shop;

    private string $site;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create(['name' => 'גואטה אביגדור']);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => self::ORIGIN, 'access_token' => self::TOKEN,
        ]));
        $this->site = SiteKeys::site(self::TOKEN);

        Features::override('shoppers.signup', true, $this->shop->id);
    }

    public function test_a_phone_is_kept_as_a_lead_however_it_was_typed_even_when_no_code_can_be_sent(): void
    {
        $response = $this->signUp('anon-aaaaaaaaaaaaaaaaaaaa', '050-123-4567');

        $response->assertOk();
        $this->assertSame('saved', $response->json('data.status'), 'no SMS provider yet, so the lead is kept without a code');
        $this->assertSame('********4567', $response->json('data.masked'));

        $identity = $this->inShop(fn () => ShopperIdentity::query()->sole());
        $this->assertSame('972501234567', $identity->contact, 'a local number becomes international, so one person is one row');
        $this->assertSame('phone', $identity->channel);
        $this->assertNull($identity->verified_at);
        $this->assertSame('v1', $identity->consent_version);

        // The same phone written differently is the same person, not a second lead.
        $this->signUp('anon-bbbbbbbbbbbbbbbbbbbb', '+972 50 123 4567')->assertOk();
        $this->assertSame(1, $this->inShop(fn () => ShopperIdentity::query()->count()));
        $this->assertSame(2, $this->inShop(fn () => ShopperVisitor::query()->count()));
        $this->assertSame([false, false], $this->inShop(fn () => ShopperVisitor::query()->pluck('verified')->all()));
    }

    public function test_an_email_gets_a_code_and_only_after_it_does_browsing_follow_another_browser(): void
    {
        Mail::fake();

        $first = 'anon-cccccccccccccccccccc';
        $second = 'anon-dddddddddddddddddddd';

        $this->viewed($first, '11', 3);
        $this->viewed($first, '12', 1);
        $this->viewed($second, '13', 2);

        $this->assertSame('code_sent', $this->signUp($first, 'Buyer@Example.COM ')->json('data.status'));
        $this->assertSame('b****@example.com', $this->inShop(fn () => ShopperIdentity::query()->sole())->contact_masked);

        $code = null;
        Mail::assertSent(VerificationCode::class, function (VerificationCode $mail) use (&$code): bool {
            $code = $mail->code;

            return $mail->hasTo('buyer@example.com');
        });
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);

        $this->assertSame('verified', $this->confirm($first, (string) $code)->json('data.status'));

        // The second browser signs up with the same address but has not proved it yet.
        $this->assertSame('code_sent', $this->signUp($second, 'buyer@example.com')->json('data.status'));
        $this->assertSame(['13'], $this->topFor($second), 'an unproved browser sees only its own browsing');

        $wrong = $this->confirm($second, '000000');
        $this->assertSame('wrong_code', $wrong->json('data.status'));
        $this->assertSame(['13'], $this->topFor($second), 'and still nothing of the first browser');

        $second_code = null;
        Mail::assertSent(VerificationCode::class, function (VerificationCode $mail) use (&$second_code): bool {
            $second_code = $mail->code;

            return true;
        });

        $this->assertSame('verified', $this->confirm($second, (string) $second_code)->json('data.status'));
        $this->assertSame(['11', '13', '12'], $this->topFor($second), 'both browsers now, most visits first');
        $this->assertNotNull($this->inShop(fn () => ShopperIdentity::query()->sole())->verified_at);
    }

    public function test_the_form_refuses_a_bad_contact_no_consent_another_origin_and_a_shop_that_is_off(): void
    {
        $visitor = 'anon-eeeeeeeeeeeeeeeeeeee';

        $this->assertSame('invalid_contact', $this->signUp($visitor, 'not a contact')->json('data.status'));
        $this->assertSame('invalid_contact', $this->signUp($visitor, '12345')->json('data.status'), 'too short for a phone');
        $this->assertSame('no_consent', $this->signUp($visitor, 'buyer@example.com', consent: false)->json('data.status'));
        $this->assertSame(0, $this->inShop(fn () => ShopperIdentity::query()->count()), 'nothing is kept without consent');

        $this->postJson("/api/v1/widget/{$this->site}/signup", ['vid' => $visitor, 'contact' => 'buyer@example.com', 'consent' => true], ['Origin' => 'https://other.test'])
            ->assertForbidden();

        $this->signUp('not-a-visitor', 'buyer@example.com')->assertStatus(422);

        Features::override('shoppers.signup', false, $this->shop->id);
        $this->signUp($visitor, 'buyer@example.com')->assertForbidden();
    }

    public function test_the_store_team_sees_the_lead_and_what_that_person_looked_at(): void
    {
        $visitor = 'anon-ffffffffffffffffffff';
        $this->inShop(fn () => CatalogProduct::query()->create([
            'shop_id' => $this->shop->id, 'external_id' => '11', 'type' => 'simple', 'status' => 'publish',
            'title' => 'תומך מדף', 'price' => '9.90', 'currency' => 'ILS', 'in_stock' => true,
            'purchasable' => true, 'hash' => md5('11'), 'payload' => [],
        ]));
        $this->viewed($visitor, '11', 3);
        $this->signUp($visitor, '050-123-4567')->assertOk();

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());

        $page = Livewire::test(ShopSignUps::class, ['shop' => $this->shop->id])
            ->assertSee('972501234567')
            ->assertSee('לא אומת')
            ->assertSee('תומך מדף')
            ->assertSee('3 צפיות')
            ->assertSee('אישר את נוסח ההסכמה v1');

        // The shopper asks to be forgotten: the contact and the link go; the browsing stays anonymous.
        $identity = $this->inShop(fn () => ShopperIdentity::query()->sole());
        $page->call('forget', $identity->id)->assertDontSee('972501234567');

        $this->assertSame(0, $this->inShop(fn () => ShopperIdentity::query()->count()));
        $this->assertSame(0, $this->inShop(fn () => ShopperVisitor::query()->count()));
        $this->assertSame(['11'], $this->topFor($visitor), 'their own browser still sees its own browsing');
    }

    private function signUp(string $visitor, string $contact, bool $consent = true): TestResponse
    {
        return $this->call(
            'POST',
            "/api/v1/widget/{$this->site}/signup",
            server: ['HTTP_ORIGIN' => self::ORIGIN, 'CONTENT_TYPE' => 'text/plain'],
            content: json_encode(['vid' => $visitor, 'contact' => $contact, 'consent' => $consent, 'locale' => 'he'], JSON_THROW_ON_ERROR),
        );
    }

    private function confirm(string $visitor, string $code): TestResponse
    {
        return $this->call(
            'POST',
            "/api/v1/widget/{$this->site}/confirm",
            server: ['HTTP_ORIGIN' => self::ORIGIN, 'CONTENT_TYPE' => 'text/plain'],
            content: json_encode(['vid' => $visitor, 'code' => $code], JSON_THROW_ON_ERROR),
        );
    }

    /** @return list<string> */
    private function topFor(string $visitor): array
    {
        $top = app(VisitHistory::class)->topProducts($this->shop->id, $this->hash($visitor), 6);

        return array_column($top, 'id');
    }

    private function viewed(string $visitor, string $product, int $times): void
    {
        $this->inShop(function () use ($visitor, $product, $times): void {
            foreach (range(1, $times) as $i) {
                AnalyticsEvent::query()->create([
                    'shop_id' => $this->shop->id, 'event_id' => 'v'.++$this->sequence, 'type' => 'page_view',
                    'page_type' => 'product', 'page_path' => '/product/'.$product, 'product_external_id' => $product,
                    'visitor_hash' => $this->hash($visitor), 'session_id' => 's1', 'preview' => false,
                    'occurred_at' => now()->subMinutes($i),
                ]);
            }
        });
    }

    private function hash(string $visitor): string
    {
        return hash('sha256', $this->shop->id.'|'.$visitor);
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }
}
