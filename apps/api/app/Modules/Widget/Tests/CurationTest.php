<?php

namespace App\Modules\Widget\Tests;

use App\Modules\Admin\Models\User;
use App\Modules\Analytics\Models\AnalyticsScore;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Models\EnrichmentProductRelation;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Widget\Actions\BuildPageBank;
use App\Modules\Widget\Filament\Operator\Pages\ProductPage;
use App\Modules\Widget\Models\WidgetCuration;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The store team's say on one page: pins, hides and additions, over code and learning. */
final class CurationTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private CatalogProduct $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        $this->page = $this->product('10', 'מסור אנכי', 'x', []);

        foreach (['21', '22', '23', '24', '25'] as $i => $id) {
            $this->relation($id, 'complement', 100 - $i);
        }
        $this->relation('31', 'alternative', 50);
        $this->product('90', 'מוצר שהצוות בחר', 'x', []);
    }

    public function test_the_team_pins_hides_and_adds_products_and_the_widget_follows(): void
    {
        $this->assertSame(['21', '22', '23', '24'], $this->ids('complement'));

        // Hide one, pin one that was a spare, add one code never found: pins first, hidden gone.
        $this->decide('complement', '22', WidgetCuration::HIDE);
        $this->decide('complement', '25', WidgetCuration::PIN);
        $this->decide('complement', '90', WidgetCuration::PIN);

        $this->assertSame(['25', '90', '21', '23'], $this->ids('complement'));

        // Learning would drop 21 and 23 after enough opens; a pin never drops.
        $this->inShop(fn () => AnalyticsScore::query()->create([
            'shop_id' => $this->shop->id, 'scope' => AnalyticsScore::SCOPE_PAGE, 'candidate' => 'complement', 'page_type' => 'product', 'page_external_id' => '10',
            'related_external_id' => '', 'exposures' => 100, 'opens' => 60, 'clicks' => 0, 'adds' => 0, 'purchases' => 0, 'value' => 0, 'score' => 0.01, 'computed_at' => now(),
        ]));
        $this->assertSame(['25', '90'], $this->ids('complement'), 'pins survive learning; the never-clicked rest is dropped');

        // A hidden section is gone; a pinned section comes first.
        $this->decide('complement', '', WidgetCuration::HIDE);
        $this->assertSame(['alternatives'], array_column($this->sections(), 'candidate'));

        $this->decide('complement', '', null);
        $this->decide('alternatives', '', WidgetCuration::PIN);
        $this->assertSame(['alternatives', 'complement'], array_column($this->sections(), 'candidate'));

        // A pin into a section code did not build creates it.
        $this->decide('family', '31', WidgetCuration::PIN);
        $this->assertSame(['31'], $this->ids('family'));

        // The explanation for the team: source, score and reasons per product, and the team's decisions.
        $bank = app(BuildPageBank::class)->handle($this->shop->id, 'product', '10', 'he', explain: true);
        $this->assertSame('test', $bank['explain']['complement']['25']['source']);
        $this->assertSame(['25', '90'], $bank['explain']['complement']['']['pinned']);
        $this->assertSame(['22'], $bank['explain']['complement']['']['hidden']);
        $this->assertArrayNotHasKey('explain', app(BuildPageBank::class)->handle($this->shop->id, 'product', '10', 'he'), 'never on the storefront');
    }

    public function test_the_operator_page_shows_why_and_saves_decisions(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(ProductPage::class, ['shop' => $this->shop->id, 'type' => 'product', 'id' => '10'])
            ->assertSee('מסור אנכי')
            ->assertSee('מתאים לקנות יחד')
            ->assertSee('ציון קשר 100')
            ->call('hide', 'complement', '21')
            ->call('startAdding', 'complement')
            ->set('addSearch', 'הצוות')
            ->assertSee('מוצר שהצוות בחר')
            ->call('add', '90')
            ->assertSee('מוצמד');

        $this->assertSame(['22', '23', '24', '90'], collect($this->ids('complement'))->sort()->values()->all());
        $this->assertSame(2, $this->inShop(fn () => WidgetCuration::query()->count()));
    }

    /** @return list<string> */
    private function ids(string $candidate): array
    {
        $section = collect($this->sections())->firstWhere('candidate', $candidate);

        return $section === null ? [] : array_column($section['products'], 'id');
    }

    /** @return list<array<string, mixed>> */
    private function sections(): array
    {
        return app(BuildPageBank::class)->handle($this->shop->id, 'product', '10', 'he')['sections'];
    }

    private function decide(string $candidate, string $item, ?string $action): void
    {
        $this->inShop(function () use ($candidate, $item, $action): void {
            $keys = ['shop_id' => $this->shop->id, 'page_type' => 'product', 'page_external_id' => '10', 'candidate' => $candidate, 'item_external_id' => $item];
            $action === null
                ? WidgetCuration::query()->where($keys)->delete()
                : WidgetCuration::query()->updateOrCreate($keys, ['action' => $action]);
        });
    }

    private function relation(string $externalId, string $kind, int $score): void
    {
        $related = $this->product($externalId, 'מוצר '.$externalId, 'x', []);

        $this->inShop(fn () => EnrichmentProductRelation::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $this->page->id, 'related_product_id' => $related->id,
            'kind' => $kind, 'source' => 'test', 'score' => $score, 'reasons' => [], 'computed_at' => now(),
        ]));
    }
}
