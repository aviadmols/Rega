<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Settings;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\ComputeProductRelations;
use App\Modules\Enrichment\Enums\RelationKind;
use App\Modules\Enrichment\Models\EnrichmentProductRelation;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * What shoppers actually put in the same basket, which is the only thing here that is evidence
 * rather than somebody's opinion.
 */
final class BoughtTogetherTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        $this->product('10', 'מקדחה נטענת 18V', 'x', []);
        $this->product('20', 'סט מקדחים לבטון', 'x', []);
        $this->product('30', 'משקפי מגן', 'x', []);
        Settings::set('enrichment.copurchase_min_orders', 3, $this->shop->id);
    }

    public function test_a_pair_that_keeps_appearing_in_one_basket_becomes_a_complement(): void
    {
        // Three shoppers bought the drill and the bits together; one also took goggles.
        $this->order(['10', '20']);
        $this->order(['10', '20']);
        $this->order(['10', '20', '30']);

        app(ComputeProductRelations::class)->handle($this->shop->id);

        $partners = $this->complementsOf('10');

        $this->assertContains('20', $partners->pluck('external_id')->all(), 'three baskets is a pattern');
        $this->assertNotContains('30', $partners->pluck('external_id')->all(), 'one basket is a coincidence');

        $bits = $partners->firstWhere('external_id', '20');
        $this->assertSame('bought_together', $bits['source']);
        $this->assertSame(3, $bits['reasons']['bought_together'], 'the evidence is kept with the relation');

        // And it is said both ways round: the bits suggest the drill too.
        $this->assertContains('10', $this->complementsOf('20')->pluck('external_id')->all());
    }

    public function test_evidence_outranks_every_opinion_about_what_goes_together(): void
    {
        $this->order(['10', '20']);
        $this->order(['10', '20']);
        $this->order(['10', '20']);

        app(ComputeProductRelations::class)->handle($this->shop->id);

        $best = $this->complementsOf('10')->first();

        $this->assertSame('bought_together', $best['source'], 'what happened beats what anyone guessed');
    }

    public function test_a_restock_of_the_whole_shop_does_not_pair_everything_with_everything(): void
    {
        foreach (range(40, 60) as $external) {
            $this->product((string) $external, "פריט {$external}", 'x', []);
        }

        $wholeShop = array_map('strval', range(40, 60));

        foreach (range(1, 5) as $ignored) {
            $this->order($wholeShop);
        }

        app(ComputeProductRelations::class)->handle($this->shop->id);

        $this->assertTrue(
            $this->complementsOf('40')->where('source', 'bought_together')->isEmpty(),
            'a basket that large is a restock, not a decision about what goes together',
        );
    }

    /** @return Collection<int, array<string, mixed>> */
    private function complementsOf(string $externalId): Collection
    {
        return $this->inShop(function () use ($externalId): Collection {
            $product = CatalogProduct::query()->where('external_id', $externalId)->sole();

            return EnrichmentProductRelation::query()
                ->with('related:id,external_id')
                ->where('product_id', $product->id)
                ->where('kind', RelationKind::Complement)
                ->orderByDesc('score')
                ->get()
                ->map(fn (EnrichmentProductRelation $r): array => [
                    'external_id' => (string) $r->related?->external_id,
                    'source' => (string) $r->source,
                    'reasons' => (array) $r->reasons,
                ]);
        });
    }

    /** @param list<string> $productIds */
    private function order(array $productIds): void
    {
        $this->inShop(fn () => AnalyticsOrder::query()->create([
            'shop_id' => $this->shop->id,
            'order_ref' => bin2hex(random_bytes(12)),
            'total' => 100,
            'currency' => 'ILS',
            'items_count' => count($productIds),
            'items' => array_map(fn (string $id): array => ['product_id' => $id, 'quantity' => 1, 'total' => 50.0], $productIds),
            'assisted' => false,
            'attributed_total' => 0,
            'ordered_at' => now()->subDays(3),
        ]));
    }
}
