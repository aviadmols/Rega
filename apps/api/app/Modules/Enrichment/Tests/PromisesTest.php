<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Enrichment\Actions\ReadPromisesInCode;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Scanning\PromiseScanner;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the store promises, read from its own words. Nothing here may say a number the store did
 * not write, and every promise keeps the sentence it came from.
 */
final class PromisesTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    public function test_a_terms_page_gives_the_promises_a_shopper_cares_about(): void
    {
        $promises = collect(PromiseScanner::shop(<<<'TXT'
            תקנון האתר
            ניתן להחזיר מוצר תוך 14 יום ולקבל החזר מלא.
            משלוח חינם בהזמנה מעל 500 ש"ח.
            אחריות יצרן ל-2 שנים על כל המוצרים.
            אספקה תוך 3 ימי עסקים.
            אפשר לשלם עד 12 תשלומים ללא ריבית.
            TXT))->keyBy('key');

        $this->assertSame('14 יום', $promises['returns']['detail']);
        $this->assertSame('500', $promises['free_shipping']['detail']);
        $this->assertSame('2 שנים', $promises['warranty']['detail']);
        $this->assertSame('3 ימי עסקים', $promises['delivery']['detail']);
        $this->assertSame('12', $promises['payments']['detail']);

        foreach ($promises as $promise) {
            $this->assertStringContainsString($promise['detail'], $promise['quote'], 'the detail is a piece of its own sentence');
        }
    }

    public function test_a_product_says_how_it_was_made_and_what_it_is_made_of(): void
    {
        $promises = collect(PromiseScanner::product('שמיכה בעבודת יד, 100% כותנה, תוצרת פורטוגל. מתאימה למיטה זוגית.'))->keyBy('key');

        $this->assertNull($promises['handmade']['detail']);
        $this->assertSame('כותנה', $promises['pure_material']['detail']);
        $this->assertSame('פורטוגל', $promises['made_in']['detail']);
    }

    public function test_a_hundred_percent_of_something_that_is_not_a_material_is_not_read_as_one(): void
    {
        $this->assertSame([], PromiseScanner::product('100% שביעות רצון מובטחת ללקוחות שלנו.'));
        $this->assertSame([], PromiseScanner::shop('הבטחה: 100% החזר על כל פריט.'));
    }

    public function test_english_pages_are_read_too(): void
    {
        $promises = collect(PromiseScanner::shop("Free shipping over 300.\nReturns accepted within 30 days.\n2 year warranty on every tool."))->keyBy('key');

        $this->assertSame('300', $promises['free_shipping']['detail']);
        $this->assertSame('30 days', $promises['returns']['detail']);
        $this->assertSame('2 year', $promises['warranty']['detail']);
    }

    public function test_free_delivery_with_no_condition_is_still_a_promise(): void
    {
        $promises = PromiseScanner::shop('אנחנו שולחים משלוח חינם לכל הארץ.');

        $this->assertSame('free_shipping', $promises[0]['key']);
        $this->assertNull($promises[0]['detail']);
    }

    public function test_reading_the_shop_writes_facts_with_their_quotes_and_takes_back_what_a_page_stopped_saying(): void
    {
        $this->buildShop();
        $page = $this->page('900', 'תקנון', 'ניתן להחזיר מוצר תוך 14 יום. משלוח חינם מעל 500 ש"ח.');
        $this->article('901', 'איך בוחרים מקדחה', 'מחזירים מקדחה תוך 7 ימים.');
        $product = $this->product('100', 'שמיכת כותנה', 'שמיכה בעבודת יד, 100% כותנה.', []);

        app(ReadPromisesInCode::class)->handle($this->shop->id);

        $facts = $this->inShop(fn () => EnrichmentFact::query()->where('kind', FactKind::Promise)->where('status', FactStatus::Approved)->get());

        $returns = $facts->firstWhere('key', 'returns');
        $this->assertNotNull($returns);
        $this->assertSame('14 יום', $returns->value_text, 'the page, not the guide');
        $this->assertStringContainsString('14 יום', (string) $returns->quote);
        $this->assertNull($returns->product_id, 'what the shop promises belongs to the shop');
        $this->assertSame($page->id, $returns->content_id);

        $material = $facts->firstWhere('key', 'pure_material');
        $this->assertSame($product->id, $material?->product_id, 'what a product is made of belongs to the product');
        $this->assertSame('כותנה', $material?->value_text);

        // The shop rewrites the page and drops the refund window.
        $this->inShop(fn () => $page->update(['body' => 'משלוח חינם מעל 500 ש"ח.', 'hash' => md5('rewritten')]));
        app(ReadPromisesInCode::class)->handle($this->shop->id);

        $still = $this->inShop(fn () => EnrichmentFact::query()->where('kind', FactKind::Promise)->where('status', FactStatus::Approved)->pluck('key')->all());
        $this->assertNotContains('returns', $still, 'a promise the page stopped making is gone');
        $this->assertContains('free_shipping', $still);
    }
}
