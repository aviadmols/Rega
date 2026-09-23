<?php

namespace App\Modules\Knowledge\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Knowledge\Filament\Operator\Pages\ShopKnowledge;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The screen says what is known, what is missing, and what the learning is following — and it
 * belongs to one shop, so it is not there when no shop is chosen.
 */
final class ShopKnowledgeScreenTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
    }

    public function test_it_shows_what_is_known_and_names_what_is_missing(): void
    {
        foreach (range(1, 4) as $i) {
            $this->product((string) $i, "מקדחה {$i}", 'x', []);
        }

        $read = $this->inShop(fn () => CatalogProduct::query()->where('external_id', '1')->sole());
        $this->inShop(fn () => EnrichmentFact::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $read->id, 'kind' => FactKind::Type,
            'key' => 'type', 'value_text' => 'מקדחה', 'quote' => 'x', 'origin' => FactOrigin::Code,
            'status' => FactStatus::Approved, 'input_hash' => 'h', 'model' => 'code',
        ]));

        app(TenantContext::class)->set($this->shop->id);
        $now = Livewire::test(ShopKnowledge::class)->instance()->now();

        $this->assertSame(4, $now['coverage']['catalog']['products']);
        $this->assertSame(25, $now['coverage']['known_share'], 'one of four');

        $gaps = collect($now['gaps'])->keyBy('key');
        $this->assertSame(3, $gaps['products_without_facts']['count']);
        $this->assertTrue($gaps->has('no_pages_shared'));

        // With nothing happening, the screen says so instead of implying it is learning.
        $this->assertSame('none', $now['signals']['optimising_for']);
        $this->assertSame('missing', $now['signals']['verdicts']['order']);
    }

    public function test_knowledge_belongs_to_a_shop_and_is_hidden_when_none_is_chosen(): void
    {
        app(TenantContext::class)->clear();

        $this->assertFalse(ShopKnowledge::canAccess(), 'no shop, no shop knowledge');
        $this->assertFalse(ShopKnowledge::shouldRegisterNavigation());

        app(TenantContext::class)->set($this->shop->id);

        $this->assertTrue(ShopKnowledge::canAccess());
    }
}
