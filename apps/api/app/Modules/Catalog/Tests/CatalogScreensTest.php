<?php

namespace App\Modules\Catalog\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Filament\Operator\Resources\CatalogContents\Pages\ListCatalogContents;
use App\Modules\Catalog\Filament\Operator\Resources\CatalogProducts\Pages\ListCatalogProducts;
use App\Modules\Catalog\Filament\Operator\Resources\CatalogProducts\Pages\ViewCatalogProduct;
use App\Modules\Catalog\Jobs\SyncCatalogJob;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

final class CatalogScreensTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_operator_sees_products_and_articles_and_starts_a_sync_on_the_worker(): void
    {
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        app(TenantContext::class)->enterUnscoped();

        $shop = Shop::factory()->create();
        StoreConnection::query()->create(['shop_id' => $shop->id, 'site_url' => 'https://store.test', 'access_token' => 'rgt_'.str_repeat('a', 48)]);
        $product = CatalogProduct::query()->create([
            'shop_id' => $shop->id, 'external_id' => '101', 'type' => 'simple', 'status' => 'publish',
            'title' => 'מסור אנכי', 'hash' => 'x', 'in_stock' => true, 'price' => '399.00',
            'payload' => ['description' => "מסור אנכי\nמשקל: 2.1 ק\"ג", 'meta' => ['product_feature_0_title' => 'הספק', 'product_feature_0_info' => '710W']],
        ]);
        CatalogContent::query()->create(['shop_id' => $shop->id, 'type' => 'post', 'external_id' => '900', 'title' => 'איך בוחרים מסור', 'hash' => 'y']);

        Livewire::test(ListCatalogProducts::class)
            ->assertCanSeeTableRecords([$product])
            ->callAction('sync_catalog', data: ['shop_id' => $shop->id])
            ->assertNotified(__('catalog::catalog.notifications.sync_queued'));

        Queue::assertPushed(SyncCatalogJob::class, fn (SyncCatalogJob $job): bool => $job->shopId === $shop->id);

        Livewire::test(ViewCatalogProduct::class, ['record' => $product->id])
            ->assertSee('משקל: 2.1')
            ->assertSee('הספק: 710W');

        Livewire::test(ListCatalogContents::class)->assertSee('איך בוחרים מסור');

        foreach (['he', 'en'] as $locale) {
            $this->withHeader('Accept-Language', $locale)->get('/operator/catalog/products')->assertOk();
            $this->withHeader('Accept-Language', $locale)->get("/operator/catalog/products/{$product->id}")->assertOk();
        }
    }
}
