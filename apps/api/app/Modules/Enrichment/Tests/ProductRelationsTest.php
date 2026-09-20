<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\ComputeProductRelations;
use App\Modules\Enrichment\Actions\ImportRelationRules;
use App\Modules\Enrichment\Actions\ReadProductsInCode;
use App\Modules\Enrichment\Enums\RelationKind;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentProductRelation;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Relations\RelationRuleSet;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Runs\Enums\RunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class ProductRelationsTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private EnrichmentVocabulary $vocabulary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        $this->vocabulary = $this->powerToolsVocabulary();
    }

    public function test_rules_are_validated_before_they_are_saved(): void
    {
        [$rules, $problems] = RelationRuleSet::parse(['schema_version' => 1, 'rules' => [
            ['key' => 'Bad Key', 'kind' => 'friend', 'from' => ['colour' => 'red'], 'to' => [], 'match' => ['smell' => true], 'limit' => 50],
        ]]);

        $this->assertNull($rules);
        $this->assertContains('rules[0].key must be snake_case', $problems);
        $this->assertContains('rules[0].kind must be one of: complement, alternative', $problems);
        $this->assertContains('rules[0].from.colour is not a known condition', $problems);
        $this->assertContains('rules[0].to needs at least one condition', $problems);
        $this->assertContains('rules[0].match.smell is not a known condition', $problems);
        $this->assertContains('rules[0].limit must be 1 to 12', $problems);

        [$template, $none] = RelationRuleSet::parse(ImportRelationRules::template('hardware-store'));
        $this->assertSame([], $none);
        $this->assertNotNull($template);
    }

    public function test_a_body_only_tool_gets_the_battery_and_charger_of_its_brand_and_voltage_with_reasons(): void
    {
        $tools = $this->category('1751', 'כלי עבודה חשמליים');

        $lamp = $this->tool('19342', 'פנס לד 18V ללא סוללה ומטען (גוף בלבד)', [$tools], ['type' => 'flashlight', 'power_source' => 'cordless', 'kit' => 'body_only', 'voltage_v' => 18], shortDescription: 'כלי עבודה מבית המותג הבינלאומי - סטנלי');
        $stanley18 = $this->tool('900', 'סוללת ליתיום 18 וולט - 4.0 AH דגם 20V', [$tools], ['type' => 'battery', 'voltage_v' => 18], brand: 'Stanley');
        $stanley54 = $this->tool('901', 'סוללת ליתיום 54 וולט - 2.5 AH דגם V60', [$tools], ['type' => 'battery', 'voltage_v' => 54], brand: 'Stanley');
        $makita18 = $this->tool('902', 'סוללה 18V 4Ah Makita BL1840B', [$tools], ['type' => 'battery', 'voltage_v' => 18], brand: 'Makita');
        $soldOut = $this->tool('903', 'סוללה ליתיום STANLEY FATMAX 4.0Ah 18V', [$tools], ['type' => 'battery', 'voltage_v' => 18], brand: 'Stanley', overrides: ['in_stock' => false]);
        $charger18 = $this->tool('904', 'מטען טעינה מהירה 18V דגם V20 של סטנלי', [$tools], ['type' => 'charger', 'voltage_v' => 18]);
        $charger54 = $this->tool('905', 'מטען מהיר 54V', [$tools], ['type' => 'charger', 'voltage_v' => 54], brand: 'Stanley');
        $chargerNoVoltage = $this->tool('906', 'מטען סטנלי', [$tools], ['type' => 'charger'], brand: 'Stanley');

        app(ReadProductsInCode::class)->handle($this->shop->id);
        app(ImportRelationRules::class)->handle($this->shop->id, ImportRelationRules::template('hardware-store'), 'test');
        $run = app(ComputeProductRelations::class)->handle($this->shop->id);
        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);

        $complements = $this->relations($lamp, RelationKind::Complement);

        $this->assertSame([$stanley18->id], $complements->where('source', 'battery_for_cordless_tool')->pluck('related_product_id')->all(), 'same brand, same voltage, in stock');
        $this->assertSame(['rule' => 'battery_for_cordless_tool', 'brand' => 'Stanley', 'voltage_v' => 18], $complements->firstWhere('source', 'battery_for_cordless_tool')->reasons);
        $this->assertEqualsCanonicalizing([$charger18->id, $chargerNoVoltage->id], $complements->where('source', 'charger_for_cordless_tool')->pluck('related_product_id')->all(), 'a charger that states another voltage is left out');
        $this->assertFalse($complements->pluck('related_product_id')->contains($stanley54->id));
        $this->assertFalse($complements->pluck('related_product_id')->contains($makita18->id));
        $this->assertFalse($complements->pluck('related_product_id')->contains($soldOut->id));
        $this->assertFalse($complements->pluck('related_product_id')->contains($charger54->id));

        $this->assertSame(1, $run->output['rules']['battery_for_cordless_tool']['from']);
    }

    public function test_merchant_links_count_both_ways_and_sizes_and_alternatives_are_found(): void
    {
        $wood = $this->category('2336', 'דו שכבתי', null, ['עצים', 'עץ אורן', 'מוקצע לא מחוטא', 'דו שכבתי']);
        $tools = $this->category('1751', 'כלי עבודה חשמליים');

        $small = $this->product('12004', 'עץ אורן דו שכבתי 85X85 מ"מ (10X10)', 'x', [$wood]);
        $large = $this->product('12007', 'עץ אורן דו שכבתי 85X135 מ"מ (10X15)', 'x', [$wood]);
        $drill = $this->tool('9236', 'גוף מברגה/מקדחה רוטטת ונטענת Makita', [$tools], ['type' => 'hammer_drill', 'power_source' => 'cordless'], brand: 'Makita', overrides: ['price' => '900.00']);
        $similar = $this->tool('14841', 'מברגה/מקדחה רוטטת נטענת DHP458 18V גוף בלבד', [$tools], ['type' => 'hammer_drill', 'power_source' => 'cordless'], brand: 'Makita', overrides: ['price' => '660.00']);
        $expensive = $this->tool('14000', 'פטישון יקר', [$tools], ['type' => 'hammer_drill', 'power_source' => 'cordless'], brand: 'Makita', overrides: ['price' => '2500.00']);
        $spray = $this->product('11209', 'ספריי שחרור ברגים 420 מ"ל WD-40', 'x', [$tools], ['payload' => ['relations' => [['type' => 'upsell', 'target' => '9236']]]]);

        app(ReadProductsInCode::class)->handle($this->shop->id);
        app(ComputeProductRelations::class)->handle($this->shop->id);

        $this->assertSame([$large->id], $this->relations($small, RelationKind::Family)->pluck('related_product_id')->all());
        $this->assertSame(['family' => 'עץ אורן דו שכבתי'], $this->relations($small, RelationKind::Family)->first()->reasons);

        $this->assertSame([$spray->id], $this->relations($drill, RelationKind::Complement)->pluck('related_product_id')->all(), 'the spray links to the drill, so the drill shows the spray');
        $this->assertSame('merchant_reverse', $this->relations($drill, RelationKind::Complement)->first()->source);

        $alternatives = $this->relations($drill, RelationKind::Alternative);
        $this->assertSame([$similar->id], $alternatives->pluck('related_product_id')->all(), 'same type and power source, within the price band');
        $this->assertSame(0.73, $alternatives->first()->reasons['price_ratio']);
        $this->assertFalse($alternatives->pluck('related_product_id')->contains($expensive->id));
    }

    public function test_products_no_vocabulary_reads_get_alternatives_from_their_category_and_complements_from_the_store_habit(): void
    {
        $hardware = $this->category('1747', 'מוצרי פרזול');
        $hinges = $this->category('2412', 'צירים', '1747', ['מוצרי פרזול', 'צירים']);
        $handles = $this->category('2416', 'ידיות', '1747', ['מוצרי פרזול', 'ידיות']);
        $sale = $this->category('2509', 'מבצעים');
        // Overrides replace the whole payload, so the categories come along with the links.
        $link = fn (string ...$targets): array => ['payload' => [
            'relations' => array_map(fn (string $t): array => ['type' => 'cross_sell', 'target' => $t], $targets),
            'categories' => [['id' => '1747', 'name' => 'מוצרי פרזול', 'path' => ['מוצרי פרזול']], ['id' => '2412', 'name' => 'צירים', 'path' => ['מוצרי פרזול', 'צירים']]],
        ]];

        // Three hinges link to handles: the store's habit. A fourth hinge has no links and no vocabulary.
        $h1 = $this->product('101', 'ציר ספר 3 אינץ', 'x', [$hardware, $hinges], ['price' => '20.00'] + $link('201', '202'));
        $h2 = $this->product('102', 'ציר ספר 4 אינץ', 'x', [$hardware, $hinges], ['price' => '25.00'] + $link('201'));
        $h3 = $this->product('103', 'ציר קפיצי', 'x', [$hardware, $hinges], ['price' => '60.00'] + $link('202'));
        $h4 = $this->product('104', 'ציר נסתר למטבח', 'x', [$hardware, $hinges, $sale], ['price' => '22.00']);
        $expensive = $this->product('105', 'ציר תעשייתי כבד', 'x', [$hardware, $hinges], ['price' => '300.00']);
        $handle1 = $this->product('201', 'ידית ארון 128 מ"מ', 'x', [$hardware, $handles], ['price' => '15.00']);
        $handle2 = $this->product('202', 'ידית דלת', 'x', [$hardware, $handles], ['price' => '80.00']);
        $this->product('203', 'ידית מגירה', 'x', [$hardware, $handles], ['price' => '12.00']);

        app(ReadProductsInCode::class)->handle($this->shop->id);
        $run = app(ComputeProductRelations::class)->handle($this->shop->id);
        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);

        $alternatives = $this->relations($h4, RelationKind::Alternative);
        $this->assertSame([$h1->id, $h2->id], $alternatives->sortByDesc('score')->pluck('related_product_id')->all(), 'same deepest category, within the price band; "sale" is not a category of its own');
        $this->assertSame(['same_category', 'צירים'], [$alternatives->first()->source, $alternatives->first()->reasons['category']]);
        $this->assertFalse($alternatives->pluck('related_product_id')->contains($expensive->id));

        $complements = $this->relations($h4, RelationKind::Complement);
        $this->assertSame('category_affinity', $complements->first()->source);
        $this->assertSame([$handle1->id, $handle2->id], $complements->sortByDesc('score')->pluck('related_product_id')->take(2)->all(), 'the most linked handles first');
        $this->assertSame(['affinity' => ['צירים', 'ידיות'], 'links' => 3], $complements->first()->reasons);
        $this->assertSame(1, $run->output['rules']['category_affinity']['pairs']);

        $this->assertTrue($this->relations($h1, RelationKind::Complement)->every(fn (EnrichmentProductRelation $r): bool => $r->source === 'merchant'), 'a product with its own links keeps them');
        $this->assertSame([], $this->relations($handle1, RelationKind::Complement)->where('source', 'category_affinity')->all(), 'one link from handles to hinges is not a habit');
    }

    /** @return Collection<int, EnrichmentProductRelation> */
    private function relations(CatalogProduct $product, RelationKind $kind): Collection
    {
        return $this->inShop(fn () => EnrichmentProductRelation::query()->where('product_id', $product->id)->where('kind', $kind)->orderByDesc('score')->get());
    }

    /**
     * A power tool with approved facts, as a model reading and a check would leave it.
     *
     * @param  array<string, string|int>  $facts  type, choices and numeric specs
     * @param  array<string, mixed>  $overrides
     */
    private function tool(string $id, string $title, array $categories, array $facts, ?string $brand = null, array $overrides = [], string $shortDescription = ''): CatalogProduct
    {
        $product = $this->product($id, $title, 'x', $categories, $overrides + ['brand' => $brand, 'price' => '400.00', 'payload' => ['short_description' => $shortDescription, 'categories' => [['id' => '1751', 'path' => ['כלי עבודה חשמליים']]]]]);

        foreach ($facts as $key => $value) {
            $kind = $key === 'type' ? 'type' : (is_int($value) ? 'spec' : 'choice');

            $this->inShop(fn () => EnrichmentFact::query()->create([
                'shop_id' => $this->shop->id, 'product_id' => $product->id, 'vocabulary_id' => $this->vocabulary->id,
                'kind' => $kind, 'key' => $key, 'value_text' => is_int($value) ? null : $value, 'value_number' => is_int($value) ? $value : null,
                'origin' => 'code+model', 'status' => 'approved', 'input_hash' => 'x',
            ]));
        }

        return $product;
    }
}
