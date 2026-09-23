<?php

namespace App\Modules\Knowledge\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Knowledge\Actions\TakeKnowledgeSnapshot;
use App\Modules\Knowledge\Models\KnowledgeSnapshot;
use App\Modules\Tenancy\Enums\Vertical;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The night writes down what is known, what is missing, and what the learning is being judged on.
 */
final class KnowledgeSnapshotTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
    }

    public function test_it_counts_what_is_known_names_what_is_missing_and_reads_the_trade(): void
    {
        $tools = $this->category('1', 'כלי עבודה');

        foreach (range(1, 12) as $i) {
            $this->product((string) $i, "מקדחה נטענת {$i}", 'x', [$tools]);
        }
        $this->article('900', 'איך בוחרים מקדחה', "טקסט.\n- נקודה ראשונה שכדאי לזכור היטב");

        // Two of the twelve have been read; the rest are the gap.
        foreach (['1', '2'] as $externalId) {
            $product = $this->inShop(fn () => CatalogProduct::query()->where('external_id', $externalId)->sole());
            $this->inShop(fn () => EnrichmentFact::query()->create([
                'shop_id' => $this->shop->id, 'product_id' => $product->id, 'kind' => FactKind::Type,
                'key' => 'type', 'value_text' => 'מקדחה', 'quote' => 'x', 'origin' => FactOrigin::Code,
                'status' => FactStatus::Approved, 'input_hash' => 'h'.$externalId, 'model' => 'code',
            ]));
        }

        app(TakeKnowledgeSnapshot::class)->handle($this->shop);

        $snapshot = app(TenantContext::class)->runUnscoped(fn () => KnowledgeSnapshot::query()->sole());

        $this->assertSame(12, $snapshot->coverage['catalog']['products']);
        $this->assertSame(1, $snapshot->coverage['catalog']['articles']);
        $this->assertSame(2, $snapshot->coverage['read_in_code']['products']);
        $this->assertSame(17, $snapshot->coverage['known_share'], 'two of twelve');

        $gaps = collect($snapshot->gaps)->keyBy('key');

        $this->assertSame(10, $gaps['products_without_facts']['count'], 'the ten nobody has read');
        $this->assertSame('enrichment.read_in_code', $gaps['products_without_facts']['fix'], 'and what would read them');
        $this->assertTrue($gaps->has('no_pages_shared'), 'the shop shares no pages, so it has no promises');

        // A catalogue of drills is a hardware shop, and nobody was asked.
        $this->assertSame(Vertical::HardwareStore, $this->shop->fresh()->vertical);
        $this->assertGreaterThan(0, $this->shop->fresh()->vertical_confidence);
    }

    public function test_with_nothing_happening_the_signals_say_so_rather_than_pretending(): void
    {
        app(TakeKnowledgeSnapshot::class)->handle($this->shop);

        $signals = app(TenantContext::class)->runUnscoped(fn () => KnowledgeSnapshot::query()->sole())->signals;

        $this->assertSame('missing', $signals['verdicts']['order']);
        $this->assertSame('missing', $signals['verdicts']['click']);
        $this->assertSame('none', $signals['optimising_for'], 'nothing is worth learning from yet');
    }

    public function test_a_trade_someone_set_by_hand_is_not_argued_with(): void
    {
        $this->shop->forceFill(['vertical' => null, 'vertical_locked' => true])->save();

        foreach (range(1, 12) as $i) {
            $this->product((string) $i, "מקדחה {$i}", 'x', []);
        }

        app(TakeKnowledgeSnapshot::class)->handle($this->shop);

        $this->assertNull($this->shop->fresh()->vertical, 'locked means locked');
    }
}
