<?php

namespace App\Modules\Widget\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Enrichment\Actions\ComputeProductRelations;
use App\Modules\Enrichment\Actions\ImportRelationRules;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Actions\ReadProductsInCode;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The circles a product page gets once code reading and relations have run. */
final class WidgetCirclesTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private const TOKEN = 'rgt_cccccccccccccccccccccccccccccccccccccccccccccccc';

    private EnrichmentVocabulary $tools;

    private EnrichmentVocabulary $wood;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN,
        ]));
        $this->tools = $this->powerToolsVocabulary();
        $this->wood = app(ImportVocabulary::class)->handle($this->shop->id, ImportVocabulary::template('wood'), 'test')['vocabulary'];
    }

    public function test_a_body_only_tool_gets_battery_level_alternatives_on_sale_and_a_comparison_key(): void
    {
        $this->category('1751', 'כלי עבודה חשמליים');
        $jigsaw = $this->tool('10', 'מסור אנכי נטען 18V גוף בלבד', ['type' => 'jigsaw', 'power_source' => 'cordless', 'kit' => 'body_only', 'voltage_v' => 18, 'weight_kg' => 1.6], 'Makita', '500.00');
        $this->tool('11', 'מסור אנכי נטען אחר', ['type' => 'jigsaw', 'power_source' => 'cordless', 'weight_kg' => 2.0], 'Einhell', '450.00', ['on_sale' => true]);
        $this->tool('12', 'מסור אנכי נטען שלישי', ['type' => 'jigsaw', 'power_source' => 'cordless', 'weight_kg' => 2.2], 'Bosch', '550.00');
        $this->tool('13', 'מסור אנכי נטען רביעי', ['type' => 'jigsaw', 'power_source' => 'cordless', 'weight_kg' => 2.5], 'Ryobi', '520.00');
        $battery = $this->tool('20', 'סוללה 18V 4Ah Makita BL1840B', ['type' => 'battery', 'voltage_v' => 18], 'Makita', '350.00');
        $this->tool('21', 'סוללה 18V 5Ah Makita BL1850B', ['type' => 'battery', 'voltage_v' => 18], 'Makita', '420.00');

        $this->runPipeline();
        $bank = $this->page('10');

        $sections = collect($bank['sections'])->keyBy('candidate');
        $this->assertSame(['position', 'specs', 'complement', 'alternatives', 'on_sale'], array_column($bank['sections'], 'candidate'));

        $this->assertStringContainsString('ברבע הנמוך מבין 4 דגמים', $sections['position']['lines'][0]['text'], 'the lightest quarter of four cordless jigsaws');
        $this->assertContains('Makita', array_column($sections['specs']['specs'], 'value'), 'the brand code read');

        $complement = $sections['complement']['products'];
        $this->assertSame(['20', '21'], array_column($complement, 'id'));
        $this->assertSame('סוללה מתאימה · Makita · 18 וולט', $complement[0]['reason']);

        $this->assertSame(['11'], array_column($sections['on_sale']['products'], 'id'));
        $this->assertTrue($sections['on_sale']['require_sale'], 'the widget checks the sale live');
        $this->assertSame('דומים במבצע', $sections['on_sale']['chip']);

        $this->assertSame('power_tools|jigsaw', $bank['compare']['key']);
        $this->assertSame($battery->external_id, '20');
    }

    public function test_wood_gets_other_sizes_what_to_choose_and_good_for_jobs_with_guides(): void
    {
        $wood = $this->category('2212', 'עצים');
        $beams = $this->category('2336', 'דו שכבתי', '2212', ['עצים', 'עץ אורן', 'מוקצע לא מחוטא', 'דו שכבתי']);
        $payload = fn (string $id) => ['meta' => ['price_text' => 'מחיר למטר'], 'attributes' => [['name' => 'אורך', 'values' => ['3 מטר', '3.30 מטר', '3.60 מטר', '5.70 מטר', '6 מטר'], 'used_for_variations' => true]],
            'categories' => [['id' => '2336', 'path' => ['עצים', 'עץ אורן', 'מוקצע לא מחוטא', 'דו שכבתי']]]];

        $beam = $this->product('12007', 'עץ אורן דו שכבתי 85X135 מ"מ (10X15)', 'x', [$wood, $beams], ['payload' => $payload('12007')]);
        $this->product('12004', 'עץ אורן דו שכבתי 85X85 מ"מ (10X10)', 'x', [$wood, $beams], ['payload' => $payload('12004')]);

        $guide = $this->article('900', 'איך בונים פרגולה', 'x');
        $this->inShop(fn () => $guide->forceFill(['url' => 'https://store.test/pergola/'])->save());
        $this->approvedFact(['content_id' => $guide->id, 'kind' => 'use', 'key' => 'use', 'value_text' => 'pergola']);

        app(ReadProductsInCode::class)->handle($this->shop->id);
        $this->approvedFact(['product_id' => $beam->id, 'vocabulary_id' => $this->wood->id, 'kind' => 'use', 'key' => 'use', 'value_text' => 'pergola']);
        $this->approvedFact(['product_id' => $beam->id, 'vocabulary_id' => $this->wood->id, 'kind' => 'use', 'key' => 'use', 'value_text' => 'fence']);
        $this->runPipeline(readInCode: false);

        $sections = collect($this->page('12007')['sections'])->keyBy('candidate');

        $this->assertSame(['12004'], array_column($sections['family']['products'], 'id'));
        $this->assertTrue($sections['family']['products'][0]['needs_options']);

        $specs = collect($sections['specs']['specs'])->pluck('value', 'label');
        $this->assertSame('קורה דו/תלת שכבתית', $specs['סוג']);
        $this->assertSame('85 מ״מ', $specs['עובי']);
        $this->assertSame('3 מטר – 6 מטר', $specs['אורך לבחירה']);
        $this->assertSame('מחיר למטר', $specs['המחיר']);

        $this->assertSame(['פרגולה', 'גדר'], $sections['good_for']['uses']);
        $this->assertSame('טוב ל: פרגולה · גדר', $sections['good_for']['chip']);
        $this->assertSame('https://store.test/pergola/', $sections['good_for']['guides'][0]['url'], 'an article a checker approved for the same job');
    }

    private function runPipeline(bool $readInCode = true): void
    {
        if ($readInCode) {
            app(ReadProductsInCode::class)->handle($this->shop->id);
        }

        app(ImportRelationRules::class)->handle($this->shop->id, ImportRelationRules::template('hardware-store'), 'test');
        app(ComputeProductRelations::class)->handle($this->shop->id);
    }

    /** @return array<string, mixed> */
    private function page(string $id): array
    {
        return $this->get('/api/v1/widget/'.SiteKeys::site(self::TOKEN).'/page?type=product&id='.$id.'&locale=he', ['Origin' => 'https://store.test'])
            ->assertOk()
            ->json();
    }

    /** @param array<string, mixed> $values */
    private function approvedFact(array $values): void
    {
        $this->inShop(fn () => EnrichmentFact::query()->create($values + [
            'shop_id' => $this->shop->id, 'origin' => 'code+model', 'status' => 'approved', 'input_hash' => 'x',
        ]));
    }

    /**
     * @param  array<string, string|int|float>  $facts
     * @param  array<string, mixed>  $overrides
     */
    private function tool(string $id, string $title, array $facts, string $brand, string $price, array $overrides = []): CatalogProduct
    {
        $product = $this->product($id, $title, 'x', [$this->inShop(fn () => CatalogCategory::query()->where('external_id', '1751')->first())], $overrides + [
            'brand' => $brand, 'price' => $price,
            'payload' => ['categories' => [['id' => '1751', 'path' => ['כלי עבודה חשמליים']]]],
        ]);

        foreach ($facts as $key => $value) {
            $kind = $key === 'type' ? 'type' : (is_string($value) ? 'choice' : 'spec');
            $this->approvedFact([
                'product_id' => $product->id, 'vocabulary_id' => $this->tools->id, 'kind' => $kind, 'key' => $key,
                'value_text' => is_string($value) ? $value : null, 'value_number' => is_string($value) ? null : $value,
                'unit' => $key === 'weight_kg' ? 'kg' : ($key === 'voltage_v' ? 'V' : null),
            ]);
        }

        return $product;
    }
}
