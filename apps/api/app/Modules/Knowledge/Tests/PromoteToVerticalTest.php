<?php

namespace App\Modules\Knowledge\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Enrichment\Models\EnrichmentContentRules;
use App\Modules\Enrichment\Models\EnrichmentContentTemplate;
use App\Modules\Enrichment\Support\ContentRules;
use App\Modules\Knowledge\Actions\PromoteToVertical;
use App\Modules\Tenancy\Enums\Vertical;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one place anything crosses between shops, and the narrowest thing that could: a word that
 * introduces a conclusion, and only once shops arrived at it separately.
 */
final class PromoteToVerticalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_word_two_shops_found_separately_becomes_the_trades_and_a_new_shop_inherits_it(): void
    {
        $first = $this->shopThatPublished(['המסקנה שלנו']);
        $this->shopThatPublished(['המסקנה שלנו']);
        $this->shopThatPublished(['משהו שרק אני אמרתי']);

        app(PromoteToVertical::class)->handle();

        $trade = EnrichmentContentTemplate::inForce(Vertical::HardwareStore->value);

        $this->assertNotNull($trade, 'the trade learned something');
        $this->assertContains('המסקנה שלנו', $trade['takeaway_markers'], 'two shops found it separately');
        $this->assertNotContains('משהו שרק אני אמרתי', $trade['takeaway_markers'], 'one shop is a quirk of that shop');

        // A shop opened today starts where the others got to, without copying anything.
        $newcomer = Shop::factory()->create(['vertical' => Vertical::HardwareStore]);
        $inherited = app(TenantContext::class)->run($newcomer->id, fn (): array => EnrichmentContentRules::inForce($newcomer->id));

        $this->assertContains('המסקנה שלנו', $inherited['takeaway_markers']);

        // And a shop with rules of its own still follows its own.
        $own = app(TenantContext::class)->run($first->id, fn (): array => EnrichmentContentRules::inForce($first->id));
        $this->assertContains('המסקנה שלנו', $own['takeaway_markers']);
    }

    public function test_a_shop_that_only_inherited_a_word_does_not_get_to_vote_for_it(): void
    {
        // Two shops of the trade, but only one ever published anything itself.
        $this->shopThatPublished(['רק אצלי']);
        Shop::factory()->create(['vertical' => Vertical::HardwareStore]);

        app(PromoteToVertical::class)->handle();

        $this->assertNull(
            EnrichmentContentTemplate::inForce(Vertical::HardwareStore->value),
            'a shop that published nothing has not found anything',
        );
    }

    public function test_shops_of_different_trades_do_not_teach_each_other(): void
    {
        $this->shopThatPublished(['משותף']);
        $other = Shop::factory()->create(['vertical' => null]);
        $this->publish($other, ['משותף']);

        app(PromoteToVertical::class)->handle();

        $this->assertNull(EnrichmentContentTemplate::inForce(Vertical::HardwareStore->value));
    }

    /** @param list<string> $markers */
    private function shopThatPublished(array $markers): Shop
    {
        $shop = Shop::factory()->create(['vertical' => Vertical::HardwareStore]);
        $this->publish($shop, $markers);

        return $shop;
    }

    /** @param list<string> $markers */
    private function publish(Shop $shop, array $markers): void
    {
        $rules = ContentRules::defaults();
        $rules['takeaway_markers'] = array_merge($rules['takeaway_markers'], $markers);
        $rules['version'] = ContentRules::VERSION + 1;

        app(TenantContext::class)->run($shop->id, fn () => EnrichmentContentRules::query()->create([
            'shop_id' => $shop->id,
            'version' => $rules['version'],
            'rules' => $rules,
            'rules_hash' => hash('sha256', (string) json_encode($rules)),
            'author' => 'audit',
            'active' => true,
        ]));
    }
}
