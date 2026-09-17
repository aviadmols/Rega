<?php

namespace App\Modules\Widget\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Enrichment\Models\EnrichmentContentProduct;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentRanking;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class PageBankTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private const TOKEN = 'rgt_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private string $site;

    private EnrichmentVocabulary $vocabulary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN,
        ]));
        $this->site = SiteKeys::site(self::TOKEN);
        $this->vocabulary = $this->powerToolsVocabulary();

        $tools = $this->category('1751', 'כלי עבודה');
        $jigsaw = $this->product('10', 'מסור אנכי נטען 18V', 'x', [$tools], [
            'url' => 'https://store.test/product/jigsaw/',
            'payload' => ['relations' => [
                ['type' => 'cross_sell', 'target' => '12'],
                ['type' => 'cross_sell', 'target' => '11'],
                ['type' => 'upsell', 'target' => '13'],
                ['type' => 'cross_sell', 'target' => '14'],
            ]],
        ]);
        $this->product('11', 'סוללה 5Ah', 'x', [$tools], ['image_url' => 'https://store.test/battery.jpg']);
        $this->product('12', 'להבים למסור אנכי', 'x', [$tools]);
        $this->product('13', 'מסור אנכי מקצועי', 'x', [$tools]);
        $this->product('14', 'מטען', 'x', [$tools], ['in_stock' => false]);

        $this->fact($jigsaw, 'type', 'type', text: 'jigsaw');
        $this->fact($jigsaw, 'spec', 'weight_kg', number: 1.6, unit: 'kg');
        $this->fact($jigsaw, 'choice', 'power_source', text: 'cordless');
        $this->fact($jigsaw, 'flag', 'brushless', text: 'true');
        $this->fact($jigsaw, 'spec', 'voltage_v', number: 18, unit: 'V', status: 'needs_person');

        $this->ranking($jigsaw, 'weight_kg', 'min', 1.6, 'kg', ['type' => 'jigsaw', 'power_source' => 'cordless'], 9);
        $this->ranking($jigsaw, 'price', 'min', 499, null, ['type' => 'jigsaw', 'power_source' => 'cordless', 'kit' => 'body_only'], 5, tied: true);

        $guide = $this->article('900', 'איך בוחרים מסור אנכי', 'x');
        $this->inShop(fn () => $guide->forceFill(['url' => 'https://store.test/guide/'])->save());
        $this->inShop(fn () => EnrichmentContentProduct::query()->create([
            'shop_id' => $this->shop->id, 'content_id' => $guide->id, 'product_id' => $jigsaw->id,
            'rank' => 1, 'score' => 1000, 'reasons' => ['mentioned' => true], 'computed_at' => now(),
        ]));
    }

    public function test_a_product_page_gets_superlatives_specs_cross_sells_and_guides_from_checked_facts(): void
    {
        $bank = $this->page('product', '10')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin')
            ->json();

        $this->assertTrue($bank['enabled']);
        $this->assertFalse($bank['preview']);
        $this->assertSame($this->shop->id, $bank['shop']);
        $this->assertSame(['selector' => 'form.cart', 'position' => 'after', 'floating' => true], $bank['placement']);
        $this->assertSame(['position', 'specs', 'complement', 'guides'], array_column($bank['sections'], 'candidate'));

        [$position, $specs, $complement, $guides] = $bank['sections'];

        $this->assertSame('משקל הכלי: הנמוך ביותר מבין 9 דגמים (מסור אנכי · נטען) · 1.6 ק״ג', $position['lines'][0]['text']);
        $this->assertSame('מחיר: מהנמוכים ביותר מבין 5 דגמים (מסור אנכי · נטען · גוף בלבד)', $position['lines'][1]['text'], 'a tie never says "the lowest"');
        $this->assertEquals(499, $position['lines'][1]['price'], 'the widget checks this against the live price');
        $this->assertSame($position['lines'][0]['text'], $bank['teaser']['text']);

        $this->assertSame([
            ['label' => 'סוג', 'value' => 'מסור אנכי'],
            ['label' => 'מקור כוח', 'value' => 'נטען'],
            ['label' => 'משקל הכלי', 'value' => '1.6 ק״ג'],
            ['label' => 'מנוע ללא פחמים', 'value' => 'כן'],
        ], $specs['specs'], 'approved facts only, in vocabulary order');

        $this->assertSame(['12', '11'], array_column($complement['products'], 'id'), 'cross-sells in the merchant order, in stock, no upsells');
        $this->assertSame('https://store.test/battery.jpg', $complement['products'][1]['image']);
        $this->assertSame('guide_card', $guides['model']);
        $this->assertSame('https://store.test/guide/', $guides['guides'][0]['url']);

        $english = $this->page('product', '10', locale: 'en')->json();
        $this->assertSame('Lowest Tool weight of 9 models (Jigsaw · Cordless) · 1.6 kg', $english['sections'][0]['lines'][0]['text']);
        $this->assertSame('ltr', $english['dir']);
    }

    public function test_an_article_gets_its_matched_products(): void
    {
        $bank = $this->page('content', '900')->assertOk()->json();

        $this->assertSame(['article_products'], array_column($bank['sections'], 'candidate'));
        $this->assertSame('10', $bank['sections'][0]['products'][0]['id']);
        $this->assertStringContainsString('משקל הכלי', $bank['sections'][0]['products'][0]['reason']);
        $this->assertSame('מוצר אחד שמתאים למדריך', $bank['teaser']['text']);
    }

    public function test_placement_and_switches_come_from_the_shop_settings(): void
    {
        Settings::set('widget.product_selector', '.my-theme .summary', $this->shop->id);
        Settings::set('widget.product_position', 'before', $this->shop->id);
        Features::override('widget.on_content', false, $this->shop->id);
        Cache::flush();

        $this->assertSame(['selector' => '.my-theme .summary', 'position' => 'before', 'floating' => true], $this->page('product', '10')->json('placement'));

        $article = $this->page('content', '900')->json();
        $this->assertFalse($article['enabled']);
        $this->assertSame([], $article['sections']);

        $this->assertSame([], $this->page('product', '999')->assertOk()->json('sections'), 'an unknown product is an empty page, not an error');
    }

    public function test_the_page_endpoint_refuses_other_sites_and_bad_input_and_knows_the_team_preview(): void
    {
        $this->page('product', '10', origin: 'https://evil.test')->assertStatus(403);
        $this->page('cart', '10')->assertStatus(422);
        $this->page('product', '10<script>')->assertStatus(422);
        $this->get('/api/v1/widget/000000000000000000000000/page?type=product&id=10')->assertStatus(404);

        $key = StoreConnection::forSite($this->site)->previewKey();
        $this->assertSame(substr(hash_hmac('sha256', 'rega-preview', hash('sha256', self::TOKEN)), 0, 32), $key);
        $this->assertTrue($this->page('product', '10', preview: $key)->json('preview'));
        $this->assertFalse($this->page('product', '10', preview: str_repeat('a', 32))->json('preview'));
    }

    public function test_the_script_is_served_with_a_version_tag(): void
    {
        $response = $this->get('/api/v1/widget/rega.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
        $this->assertStringContainsString('RegaContext', (string) $response->getContent());

        $this->get('/api/v1/widget/rega.js', ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
    }

    public function test_the_operator_gets_preview_links_and_the_placement(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        $key = StoreConnection::forSite($this->site)->previewKey();

        foreach (['he', 'en'] as $locale) {
            $this->withHeader('Accept-Language', $locale)
                ->get('/operator/storefront-preview?shop='.$this->shop->id)
                ->assertOk()
                ->assertSee('https://store.test/product/jigsaw/?rega_preview='.$key, false)
                ->assertSee('https://store.test/guide/?rega_preview='.$key, false)
                ->assertSee('form.cart');
        }
    }

    private function page(string $type, string $id, string $locale = 'he', string $origin = 'https://store.test', ?string $preview = null): TestResponse
    {
        $query = http_build_query(array_filter(['type' => $type, 'id' => $id, 'locale' => $locale, 'preview' => $preview]));

        return $this->get("/api/v1/widget/{$this->site}/page?{$query}", ['Origin' => $origin]);
    }

    private function fact(CatalogProduct $product, string $kind, string $key, ?string $text = null, ?float $number = null, ?string $unit = null, string $status = 'approved'): void
    {
        $this->inShop(fn () => EnrichmentFact::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $product->id, 'vocabulary_id' => $this->vocabulary->id,
            'kind' => $kind, 'key' => $key, 'value_text' => $text, 'value_number' => $number, 'unit' => $unit,
            'origin' => 'code+model', 'status' => $status, 'input_hash' => 'x',
        ]));
    }

    /** @param array<string, string> $facets */
    private function ranking(CatalogProduct $product, string $metric, string $direction, float $value, ?string $unit, array $facets, int $size, bool $tied = false): void
    {
        $this->inShop(fn () => EnrichmentRanking::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $product->id, 'metric' => $metric, 'direction' => $direction,
            'rank' => 1, 'tied' => $tied, 'set_size' => $size,
            'set_key' => 'power_tools|'.implode('|', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($facets), $facets)),
            'set_facets' => $facets, 'value' => $value, 'unit' => $unit, 'computed_at' => now(),
        ]));
    }
}
