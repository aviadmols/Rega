<?php

namespace App\Modules\Analytics\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Actions\ComputeScores;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\AnalyticsScore;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A share of shoppers is kept out of the learning, so the learning can be measured rather than
 * only described.
 */
final class HoldoutTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create();
    }

    public function test_what_a_held_out_shopper_does_never_reaches_the_scores(): void
    {
        $this->beacon('exposure', holdout: false);
        $this->beacon('open', holdout: false);
        $this->beacon('exposure', holdout: true);
        $this->beacon('open', holdout: true);
        $this->beacon('click', holdout: true);

        app(ComputeScores::class)->handle($this->shop->id);

        $module = app(TenantContext::class)->run($this->shop->id, fn () => AnalyticsScore::query()
            ->where('scope', AnalyticsScore::SCOPE_MODULE)
            ->where('candidate', 'complement')
            ->first());

        $this->assertNotNull($module);
        $this->assertSame(1, $module->exposures, 'only the shopper who was not held out');
        $this->assertSame(1, $module->opens);
        $this->assertSame(0, $module->clicks, 'the control group is measured, never learned from');
    }

    private function beacon(string $type, bool $holdout): void
    {
        app(TenantContext::class)->run($this->shop->id, fn () => AnalyticsEvent::query()->create([
            'shop_id' => $this->shop->id,
            'event_id' => bin2hex(random_bytes(11)),
            'type' => $type,
            'page_type' => 'product',
            'page_path' => '/p/10',
            'product_external_id' => '10',
            'candidate_id' => 'complement',
            'model' => 'complement',
            'slot' => 'panel',
            'visitor_hash' => hash('sha256', $holdout ? 'a' : 'b'),
            'session_id' => 'sess',
            'preview' => false,
            'holdout' => $holdout,
            'occurred_at' => now()->subHour(),
        ]));
    }
}
