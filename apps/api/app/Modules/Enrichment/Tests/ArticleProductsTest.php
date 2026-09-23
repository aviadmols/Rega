<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Enrichment\Actions\MatchProductsToArticles;
use App\Modules\Enrichment\Models\EnrichmentContentProduct;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Runs\Enums\RunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ArticleProductsTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    public function test_an_article_gets_linked_products_first_then_in_stock_products_from_approved_categories(): void
    {
        $this->buildShop();
        $wood = $this->category('100', 'עצים');
        $decks = $this->category('101', 'דקים', '100', ['עצים', 'דקים']);
        $tools = $this->category('200', 'כלי עבודה');

        $this->product('1', 'דק איפאה 21 מ"מ', 'x', [$wood, $decks]);
        $this->product('2', 'דק אורן מחוטא', 'x', [$wood, $decks]);
        $this->product('3', 'דק במבוק', 'x', [$wood, $decks], ['in_stock' => false]);
        $this->product('4', 'קרש אורן', 'x', [$wood]);
        $this->product('5', 'מברגת אימפקט לדקים', 'x', [$tools]);

        $guide = $this->article('900', 'כיצד לבחור דק', 'דק איפאה או דק אורן? מדריך לבחירת דק. בורגי דק ומברגה.');
        $this->inShop(fn () => $guide->forceFill(['product_external_ids' => ['5']])->save());
        $storePage = $this->article('901', 'מחסן עצים בתל אביב', 'בואו לבקר');

        $this->fact($guide, 'shopper_value', 'shopper_value', 'high');
        $this->fact($guide, 'category', 'category', '101', 'עצים › דקים');
        $this->fact($storePage, 'shopper_value', 'shopper_value', 'none');
        $this->fact($storePage, 'category', 'category', '100', 'עצים');

        $run = app(MatchProductsToArticles::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Succeeded, $run->status);

        $links = $this->inShop(fn () => EnrichmentContentProduct::query()->with(['product', 'content'])->orderBy('rank')->get());
        $this->assertSame(['5', '1', '2'], $links->pluck('product.external_id')->all(), 'linked first, then the deck with more words in common, out of stock never');
        $this->assertTrue($links->every(fn (EnrichmentContentProduct $l): bool => $l->content->external_id === '900'), 'a store page gets no products');
        $this->assertTrue($links->first()->reasons['mentioned']);
        $this->assertSame('עצים › דקים', $links->get(1)->reasons['category']);

        $this->actingAs(User::factory()->operator()->create());
        // A shop-owned screen opens once the panel is inside a shop, the way the picker sets it.
        $this->post('/admin/shop', ['shop' => $this->shop->id]);

        $this->get('/operator/enrichment/article-products')
            ->assertOk();
    }

    private function fact(CatalogContent $content, string $kind, string $key, string $value, ?string $quote = null): void
    {
        $this->inShop(fn () => EnrichmentFact::query()->create([
            'shop_id' => $this->shop->id, 'content_id' => $content->id, 'kind' => $kind, 'key' => $key,
            'value_text' => $value, 'quote' => $quote, 'origin' => 'model', 'status' => 'approved', 'input_hash' => 'x',
        ]));
    }
}
