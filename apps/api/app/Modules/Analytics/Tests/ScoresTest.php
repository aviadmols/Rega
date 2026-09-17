<?php

namespace App\Modules\Analytics\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Actions\ComputeScores;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Analytics\Models\AnalyticsScore;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ScoresTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => 'rgt_dddddddddddddddddddddddddddddddddddddddddddddddd',
        ]));
    }

    public function test_sections_are_scored_per_shop_per_page_and_per_product_with_a_purchase_worth_double_an_add(): void
    {
        // Page 10: complements seen 50 times, opened 10 times, product 55 clicked 3 times, added and bought.
        $this->events('complement', 'exposure', 50, page: '10');
        $this->events('complement', 'open', 10, page: '10');
        $this->events('complement', 'click', 3, page: '10', data: ['item_external_id' => '55']);
        $this->events('complement', 'add_to_cart', 1, page: '10', data: ['item_external_id' => '55', 'source' => 'widget', 'result' => 'added', 'visitor_hash' => 'buyer']);
        $this->events('specs', 'exposure', 50, page: '10');
        $this->events('specs', 'open', 2, page: '10');
        // Page 11: complements seen 10 times, never opened.
        $this->events('complement', 'exposure', 10, page: '11');
        // The team's preview visits and events older than the window do not count.
        $this->events('complement', 'click', 100, page: '10', data: ['item_external_id' => '56', 'preview' => true]);
        $this->events('complement', 'click', 100, page: '10', data: ['item_external_id' => '57', 'occurred_at' => now()->subDays(60)]);

        app(TenantContext::class)->run($this->shop->id, fn () => AnalyticsOrder::query()->create([
            'shop_id' => $this->shop->id, 'order_ref' => hash('sha256', 'order-1'), 'total' => 150, 'currency' => 'ILS',
            'items_count' => 1, 'items' => [['product_id' => '55', 'quantity' => 1]], 'visitor_hash' => 'buyer',
            'assisted' => true, 'attributed_total' => 150, 'attributed_items' => ['55'], 'ordered_at' => now(),
        ]));

        $run = app(ComputeScores::class)->handle($this->shop->id);
        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);

        $scores = app(TenantContext::class)->run($this->shop->id, fn () => AnalyticsScore::query()->get());
        $module = $scores->where('scope', AnalyticsScore::SCOPE_MODULE)->keyBy('candidate');
        $page = fn (string $candidate, string $id): AnalyticsScore => $scores->where('scope', AnalyticsScore::SCOPE_PAGE)->where('candidate', $candidate)->firstWhere('page_external_id', $id);

        // 10 opens + 2 × 3 clicks + 4 × 1 add + 8 × 1 purchase, over 60 exposures.
        $this->assertSame(28, $module['complement']->value);
        $this->assertSame(1, $module['complement']->purchases);
        $this->assertSame(3, $module['complement']->clicks, 'preview and old clicks left out');
        $this->assertEqualsWithDelta(28 / 60, $module['complement']->score, 0.0001);
        $this->assertEqualsWithDelta(2 / 50, $module['specs']->score, 0.0001);

        // A page's score leans on the shop-wide rate: (value + 20 × rate) / (exposures + 20).
        $this->assertEqualsWithDelta((28 + 20 * 28 / 60) / 70, $page('complement', '10')->score, 0.0001);
        $this->assertEqualsWithDelta((20 * 28 / 60) / 30, $page('complement', '11')->score, 0.0001, 'never opened, still above zero while data is thin');
        $this->assertSame('product', $page('complement', '10')->page_type);

        $related = $scores->where('scope', AnalyticsScore::SCOPE_RELATED)->sole();
        $this->assertSame(['complement', '10', '55'], [$related->candidate, $related->page_external_id, $related->related_external_id]);
        $this->assertSame(18, $related->value, '2 × 3 clicks + 4 × 1 add + 8 × 1 purchase');
        $this->assertSame(10, $related->exposures, 'the openings of the section on that page');

        $this->assertSame('complement', $run->output['modules'][0]['module']);

        // A second night replaces the scores, it does not add to them.
        app(ComputeScores::class)->handle($this->shop->id);
        $this->assertSame($scores->count(), app(TenantContext::class)->run($this->shop->id, fn () => AnalyticsScore::query()->count()));

        $this->artisan('analytics:scores')->assertSuccessful();
    }

    /** @param array<string, mixed> $data */
    private function events(string $candidate, string $type, int $count, string $page, array $data = []): void
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = $data + [
                'shop_id' => $this->shop->id,
                'event_id' => 'evt'.str_pad((string) ++$this->sequence, 16, '0', STR_PAD_LEFT),
                'type' => $type,
                'page_type' => 'product',
                'page_path' => '/product/'.$page.'/',
                'product_external_id' => $page,
                'model' => $candidate,
                'candidate_id' => $candidate,
                'slot' => 'panel',
                'visitor_hash' => 'visitor'.$i,
                'session_id' => 'session'.$i,
                'preview' => false,
                'occurred_at' => now()->subDay(),
                'created_at' => now(),
            ];
        }

        app(TenantContext::class)->run($this->shop->id, fn () => AnalyticsEvent::query()->insert($rows));
    }
}
