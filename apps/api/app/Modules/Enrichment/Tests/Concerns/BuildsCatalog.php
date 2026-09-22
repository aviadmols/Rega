<?php

namespace App\Modules\Enrichment\Tests\Concerns;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Tenancy\Models\Shop;

/** A small catalog shaped like the pilot store, built straight into the tables. */
trait BuildsCatalog
{
    protected Shop $shop;

    protected function buildShop(): Shop
    {
        return $this->shop = Shop::factory()->create();
    }

    protected function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }

    protected function category(string $externalId, string $name, ?string $parent = null, array $path = []): CatalogCategory
    {
        return $this->inShop(fn () => CatalogCategory::query()->create([
            'shop_id' => $this->shop->id,
            'external_id' => $externalId,
            'parent_external_id' => $parent,
            'name' => $name,
            'path' => $path === [] ? [$name] : $path,
            'depth' => max(0, count($path) - 1),
            'hash' => md5($externalId.$name),
        ]));
    }

    /**
     * @param  list<CatalogCategory>  $categories
     * @param  array<string, mixed>  $overrides
     */
    protected function product(string $externalId, string $title, string $description, array $categories, array $overrides = []): CatalogProduct
    {
        return $this->inShop(function () use ($externalId, $title, $description, $categories, $overrides): CatalogProduct {
            $product = CatalogProduct::query()->create(array_replace([
                'shop_id' => $this->shop->id,
                'external_id' => $externalId,
                'type' => 'simple',
                'status' => 'publish',
                'title' => $title,
                'price' => '499.00',
                'currency' => 'ILS',
                'in_stock' => true,
                'purchasable' => true,
                'hash' => md5($title.$description),
                'payload' => [
                    'title' => $title,
                    'short_description' => '',
                    'description' => $description,
                    'categories' => array_map(fn (CatalogCategory $c): array => ['id' => $c->external_id, 'name' => $c->name, 'path' => $c->path], $categories),
                    'attributes' => [],
                    'meta' => [],
                ],
            ], $overrides));

            $product->categories()->sync(array_map(fn (CatalogCategory $c): string => $c->id, $categories));

            return $product;
        });
    }

    protected function article(string $externalId, string $title, string $body): CatalogContent
    {
        return $this->inShop(fn () => CatalogContent::query()->create([
            'shop_id' => $this->shop->id,
            'type' => 'post',
            'external_id' => $externalId,
            'title' => $title,
            'excerpt' => mb_substr($body, 0, 100),
            'body' => $body,
            'hash' => md5($title.$body),
        ]));
    }

    /** A page of the shop itself: the terms, shipping, returns. Not a guide. */
    protected function page(string $externalId, string $title, string $body): CatalogContent
    {
        return $this->inShop(fn () => CatalogContent::query()->create([
            'shop_id' => $this->shop->id,
            'type' => 'page',
            'external_id' => $externalId,
            'title' => $title,
            'excerpt' => mb_substr($body, 0, 100),
            'body' => $body,
            'hash' => md5($title.$body),
        ]));
    }

    protected function powerToolsVocabulary(): EnrichmentVocabulary
    {
        $data = ImportVocabulary::template('power-tools');
        $data['root_category_external_id'] = '1751';
        $data['excluded_category_external_ids'] = ['2954'];

        return app(ImportVocabulary::class)->handle($this->shop->id, $data, 'test')['vocabulary'];
    }
}
