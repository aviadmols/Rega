<?php

namespace App\Modules\Analytics\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Actions\ComputePriors;
use App\Modules\Analytics\Models\AnalyticsPrior;
use App\Modules\Analytics\Models\AnalyticsScore;
use App\Modules\Tenancy\Enums\Vertical;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a trade has learned, so a shop that opened this morning does not start from the order of
 * a loop.
 */
final class PriorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_trade_learns_from_its_shops_and_one_shop_is_not_a_trade(): void
    {
        $first = Shop::factory()->create(['vertical' => Vertical::HardwareStore]);
        $second = Shop::factory()->create(['vertical' => Vertical::HardwareStore]);
        $alone = Shop::factory()->create(['vertical' => null]);

        // In both shops, complements are opened far more often than alternatives.
        $this->scored($first, 'complement', exposures: 1000, opens: 400, clicks: 100);
        $this->scored($first, 'alternatives', exposures: 1000, opens: 100, clicks: 10);
        $this->scored($second, 'complement', exposures: 500, opens: 250, clicks: 60);
        $this->scored($second, 'alternatives', exposures: 500, opens: 40, clicks: 5);
        // And one shop, on its own, loves something nobody else has seen.
        $this->scored($alone, 'on_sale', exposures: 900, opens: 800, clicks: 400);

        app(ComputePriors::class)->handle();

        $trade = AnalyticsPrior::forVertical(Vertical::HardwareStore->value);

        $this->assertSame(['complement', 'alternatives'], array_keys($trade), 'best first');
        $this->assertGreaterThan($trade['alternatives'], $trade['complement']);
        $this->assertSame([], AnalyticsPrior::forVertical(null), 'a shop with no trade teaches nobody');
        $this->assertArrayNotHasKey('on_sale', $trade, 'and one shop alone is not a trade');
    }

    public function test_a_trade_with_a_single_shop_produces_nothing(): void
    {
        $only = Shop::factory()->create(['vertical' => Vertical::HardwareStore]);
        $this->scored($only, 'complement', exposures: 5000, opens: 4000, clicks: 2000);

        app(ComputePriors::class)->handle();

        $this->assertSame([], AnalyticsPrior::forVertical(Vertical::HardwareStore->value));
    }

    private function scored(Shop $shop, string $candidate, int $exposures, int $opens, int $clicks): void
    {
        app(TenantContext::class)->run($shop->id, fn () => AnalyticsScore::query()->create([
            'shop_id' => $shop->id,
            'scope' => AnalyticsScore::SCOPE_MODULE,
            'candidate' => $candidate,
            'page_type' => '',
            'page_external_id' => '',
            'related_external_id' => '',
            'exposures' => $exposures,
            'opens' => $opens,
            'clicks' => $clicks,
            'adds' => 0,
            'purchases' => 0,
            'value' => 0,
            'score' => 0.5,
            'computed_at' => now(),
        ]));
    }
}
