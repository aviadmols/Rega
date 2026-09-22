<?php

namespace App\Modules\Analytics\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Actions\ComputePopularity;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Analytics\Models\AnalyticsPopularity;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class PopularityTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create();
        app(TenantContext::class)->runUnscoped(function (): void {
            StoreConnection::query()->create([
                'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => 'rgt_eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
            ]);
            // Twenty products on sale, one the store stopped publishing: the top 10% is two products.
            foreach (range(1, 21) as $i) {
                CatalogProduct::query()->create([
                    'shop_id' => $this->shop->id, 'external_id' => (string) $i, 'type' => 'simple', 'status' => 'publish',
                    'title' => "מוצר $i", 'price' => '10.00', 'currency' => 'ILS', 'in_stock' => true, 'purchasable' => true,
                    'hash' => md5((string) $i), 'payload' => [], 'removed_at' => $i === 21 ? now() : null,
                ]);
            }
        });
    }

    public function test_adds_and_orders_are_counted_per_product_and_the_top_share_with_enough_activity_is_popular(): void
    {
        // Product 1: six adds from the store's own button, one from the widget, in two orders (three pieces).
        $this->adds('1', 6, source: 'page');
        $this->adds('1', 1, source: 'widget');
        $this->order([['product_id' => '1', 'quantity' => 2], ['product_id' => '2', 'quantity' => 1]]);
        $this->order([['product_id' => '1', 'quantity' => 1]]);
        // Product 2: four adds and that one order. Product 3: five adds, enough for the threshold but third in rank.
        $this->adds('2', 4);
        $this->adds('3', 5);
        // Product 8: one add, below the threshold whatever the rank.
        $this->adds('8', 1);
        // Not counted: adds that did not go through, the team's preview visits, and anything older than the window.
        $this->adds('4', 5, result: 'needs_options');
        $this->adds('5', 5, preview: true);
        $this->adds('6', 5, at: now()->subDays(45));
        $this->order([['product_id' => '7', 'quantity' => 9]], now()->subDays(45));

        $run = app(ComputePopularity::class)->handle($this->shop->id);
        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);

        $rows = app(TenantContext::class)->run($this->shop->id, fn () => AnalyticsPopularity::query()->get()->keyBy('product_external_id'));

        $this->assertSame(['1', '2', '3', '8'], $rows->keys()->map(fn ($id): string => (string) $id)->sort()->values()->all(), 'products with activity in the window only');
        $this->assertSame([7, 2, 3, 11, 1, true], [$rows['1']->adds, $rows['1']->orders, $rows['1']->units, $rows['1']->score, $rows['1']->rank, $rows['1']->popular]);
        $this->assertSame([4, 1, 1, 6, 2, true], [$rows['2']->adds, $rows['2']->orders, $rows['2']->units, $rows['2']->score, $rows['2']->rank, $rows['2']->popular]);
        $this->assertSame([5, 0, 5, 3, false], [$rows['3']->adds, $rows['3']->orders, $rows['3']->score, $rows['3']->rank, $rows['3']->popular], 'enough activity, but outside the top share');
        $this->assertSame([1, 4, false], [$rows['8']->score, $rows['8']->rank, $rows['8']->popular]);
        $this->assertSame(30, $rows['1']->window_days);

        $this->assertSame(20, $run->output['catalog_products'], 'the removed product is not part of the share');
        $this->assertSame(2, $run->output['top_ranks']);
        $this->assertSame(2, $run->output['popular']);
        $this->assertSame(2, $run->output['orders']);
        $this->assertSame('1', $run->output['top'][0]['product']);

        // The next night starts over: yesterday's counts do not pile up.
        app(ComputePopularity::class)->handle($this->shop->id);
        $this->assertSame(7, app(TenantContext::class)->run($this->shop->id, fn () => AnalyticsPopularity::query()->where('product_external_id', '1')->value('adds')));
    }

    private function adds(string $product, int $times, string $source = 'page', string $result = 'added', bool $preview = false, ?Carbon $at = null): void
    {
        app(TenantContext::class)->run($this->shop->id, function () use ($product, $times, $source, $result, $preview, $at): void {
            foreach (range(1, $times) as $i) {
                AnalyticsEvent::query()->create([
                    'shop_id' => $this->shop->id, 'event_id' => 'e'.++$this->sequence, 'type' => 'add_to_cart', 'page_type' => 'product',
                    'page_path' => '/product/'.$product, 'product_external_id' => $product, 'item_external_id' => $product,
                    'candidate_id' => $source === 'widget' ? 'complement' : null, 'source' => $source, 'result' => $result, 'quantity' => 1,
                    'visitor_hash' => 'v'.$i, 'session_id' => 's'.$i, 'preview' => $preview, 'occurred_at' => $at ?? now()->subHours($i),
                ]);
            }
        });
    }

    /** @param  list<array{product_id: string, quantity: int}>  $items */
    private function order(array $items, ?Carbon $at = null): void
    {
        app(TenantContext::class)->run($this->shop->id, fn () => AnalyticsOrder::query()->create([
            'shop_id' => $this->shop->id, 'order_ref' => hash('sha256', 'order-'.++$this->sequence), 'total' => 100, 'currency' => 'ILS',
            'items_count' => array_sum(array_column($items, 'quantity')), 'items' => $items, 'ordered_at' => $at ?? now()->subHours(1),
        ]));
    }
}
