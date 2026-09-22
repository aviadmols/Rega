<?php

namespace App\Modules\Widget\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Assistant\Filament\Merchant\Pages\ShopQuestions;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Shoppers\Filament\Merchant\Pages\ShopSignUps;
use App\Modules\Shoppers\Models\ShopperIdentity;
use App\Modules\Tenancy\Models\Shop;
use App\Modules\Widget\Filament\Merchant\Pages\ProductPage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every shop keeps to itself. A merchant opens their own screens and sees their own rows; naming
 * another shop in the address changes nothing, because the shop comes from the tenant, not the URL.
 */
final class MerchantPanelTest extends TestCase
{
    use RefreshDatabase;

    private Shop $mine;

    private Shop $theirs;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = Shop::factory()->create(['name' => 'החנות שלי']);
        $this->theirs = Shop::factory()->create(['name' => 'חנות אחרת']);

        $this->merchant = User::factory()->create();
        $this->merchant->attachShop($this->mine, ShopRole::Owner);

        $this->seedShop($this->mine, 'מדף אורן שלי', '0501111111', 'מה המידות שלו?');
        $this->seedShop($this->theirs, 'מדף אורן שלהם', '0502222222', 'כמה הוא שוקל?');

        $this->actingAs($this->merchant);
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->mine);
    }

    public function test_the_sign_ups_page_shows_this_shops_people_and_no_one_elses(): void
    {
        Livewire::test(ShopSignUps::class)
            ->assertSee('972501111111')
            ->assertDontSee('972502222222')
            // The shop is not a choice here, and naming another one changes nothing.
            ->set('shop', $this->theirs->id)
            ->assertSee('972501111111')
            ->assertDontSee('972502222222');

        $this->assertFalse(Livewire::test(ShopSignUps::class)->instance()->picksShop());
    }

    public function test_the_questions_page_shows_this_shops_questions_and_no_one_elses(): void
    {
        Livewire::test(ShopQuestions::class)
            ->assertSee('מה המידות שלו?')
            ->assertDontSee('כמה הוא שוקל?')
            ->set('shop', $this->theirs->id)
            ->assertSee('מה המידות שלו?')
            ->assertDontSee('כמה הוא שוקל?');
    }

    public function test_the_page_screen_searches_only_this_shop(): void
    {
        $page = Livewire::test(ProductPage::class)->set('search', 'מדף אורן');

        $titles = $page->instance()->matches()->pluck('title')->all();
        $this->assertSame(['מדף אורן שלי'], $titles, 'the other shop is not in the catalog this merchant searches');

        // Even pointed at the other shop and its product, the screen stays on this shop.
        $page->set('shop', $this->theirs->id)->set('id', '10');
        $this->assertSame($this->mine->id, $page->instance()->shop);
    }

    public function test_an_operator_still_picks_a_shop_on_the_same_screens(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        Filament::setCurrentPanel(Filament::getPanel(User::OPERATOR_PANEL));

        $operator = Livewire::test(\App\Modules\Shoppers\Filament\Operator\Pages\ShopSignUps::class);

        $this->assertTrue($operator->instance()->picksShop());
        $this->assertCount(2, $operator->instance()->shops(), 'an operator sees every shop');
    }

    private function seedShop(Shop $shop, string $productTitle, string $phone, string $question): void
    {
        app(TenantContext::class)->run($shop->id, function () use ($shop, $productTitle, $phone, $question): void {
            Features::override('shoppers.signup', true, $shop->id);

            $product = CatalogProduct::query()->create([
                'shop_id' => $shop->id, 'external_id' => '10', 'type' => 'simple', 'status' => 'publish',
                'title' => $productTitle, 'price' => '99.00', 'currency' => 'ILS', 'in_stock' => true,
                'purchasable' => true, 'hash' => md5($productTitle), 'payload' => [],
            ]);

            ShopperIdentity::query()->create([
                'shop_id' => $shop->id, 'channel' => 'phone', 'contact_hash' => hash('sha256', $phone),
                'contact' => '972'.mb_substr($phone, 1), 'contact_masked' => '****'.mb_substr($phone, -4),
                'consented_at' => now(), 'consent_version' => 'v1', 'last_seen_at' => now(),
            ]);

            AssistantAnswer::query()->create([
                'shop_id' => $shop->id, 'product_id' => $product->id, 'question_key' => hash('sha256', $question),
                'question' => $question, 'answer' => 'תשובה.', 'outcome' => AssistantAnswer::ANSWERED,
                'source' => 'store', 'status' => AssistantAnswer::SHOWN, 'prompt_version' => 2,
                'asked_count' => 1, 'last_asked_at' => now(),
            ]);
        });
    }
}
