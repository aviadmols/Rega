<?php

namespace App\Modules\Knowledge\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Knowledge\Actions\MeasureLearning;
use App\Modules\Knowledge\Models\LearningMeasurement;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The measurement is allowed to say "too early", and for a young shop it usually should.
 */
final class MeasureLearningTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create();
        Settings::set('analytics.measure_min_exposures', 20, $this->shop->id);
    }

    public function test_a_learned_order_that_is_opened_more_often_is_called_an_improvement(): void
    {
        // The learned side: 30 opens from 100. The control: 20 from 100.
        $this->beacons('exposure', 100, holdout: false);
        $this->beacons('open', 30, holdout: false);
        $this->beacons('exposure', 100, holdout: true);
        $this->beacons('open', 20, holdout: true);

        app(MeasureLearning::class)->handle($this->shop->id);
        $measurement = $this->measurement();

        $this->assertSame(LearningMeasurement::HELPED, $measurement->verdict);
        $this->assertSame(0.3, $measurement->effect['open']['learned']);
        $this->assertSame(0.2, $measurement->effect['open']['control']);
        $this->assertEqualsWithDelta(0.1, $measurement->effect['open']['difference'], 0.0001);
    }

    public function test_a_learned_order_that_does_worse_is_said_to_do_worse(): void
    {
        $this->beacons('exposure', 100, holdout: false);
        $this->beacons('open', 10, holdout: false);
        $this->beacons('exposure', 100, holdout: true);
        $this->beacons('open', 40, holdout: true);

        app(MeasureLearning::class)->handle($this->shop->id);

        $this->assertSame(LearningMeasurement::HURT, $this->measurement()->verdict, 'this is what a control group is for');
    }

    public function test_too_little_traffic_is_not_a_result(): void
    {
        $this->beacons('exposure', 8, holdout: false);
        $this->beacons('open', 8, holdout: false);
        $this->beacons('exposure', 5, holdout: true);

        app(MeasureLearning::class)->handle($this->shop->id);

        $this->assertSame(LearningMeasurement::TOO_EARLY, $this->measurement()->verdict);
    }

    public function test_with_nobody_held_out_there_is_nothing_to_compare_against(): void
    {
        $this->beacons('exposure', 500, holdout: false);
        $this->beacons('open', 300, holdout: false);

        app(MeasureLearning::class)->handle($this->shop->id);

        $this->assertSame(LearningMeasurement::NO_CONTROL, $this->measurement()->verdict);
    }

    private function measurement(): LearningMeasurement
    {
        return app(TenantContext::class)->run($this->shop->id, fn () => LearningMeasurement::query()->sole());
    }

    private function beacons(string $type, int $times, bool $holdout): void
    {
        app(TenantContext::class)->run($this->shop->id, function () use ($type, $times, $holdout): void {
            foreach (range(1, $times) as $i) {
                AnalyticsEvent::query()->create([
                    'shop_id' => $this->shop->id,
                    'event_id' => bin2hex(random_bytes(11)),
                    'type' => $type,
                    'page_type' => 'product',
                    'page_path' => '/p/10',
                    'product_external_id' => '10',
                    'candidate_id' => 'complement',
                    'model' => 'complement',
                    'slot' => 'panel',
                    'visitor_hash' => hash('sha256', ($holdout ? 'a' : 'b').$i),
                    'session_id' => 'sess',
                    'preview' => false,
                    'holdout' => $holdout,
                    'occurred_at' => now()->subDay(),
                ]);
            }
        });
    }
}
