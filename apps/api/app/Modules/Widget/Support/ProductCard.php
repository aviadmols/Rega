<?php

namespace App\Modules\Widget\Support;

use App\Modules\Catalog\Models\CatalogProduct;
use Illuminate\Support\Collection;

/**
 * One product as the widget shows it. Prices and stock here are only what the catalog last read;
 * the widget replaces both with live values from the store before rendering.
 */
final class ProductCard
{
    /**
     * @param  Collection<int, CatalogProduct>  $products
     * @param  Collection<string, string>|null  $reasons  why this product is here, by product id
     * @return list<array<string, mixed>>
     */
    public static function many(Collection $products, ?Collection $reasons = null): array
    {
        return $products->values()->map(fn (CatalogProduct $p): array => array_filter([
            'id' => $p->external_id,
            'title' => $p->title,
            'url' => $p->url,
            'image' => $p->image_url,
            'price' => $p->price === null ? null : (float) $p->price,
            'currency' => $p->currency,
            'type' => $p->type,
            // Shown next to the price, e.g. "מחיר למטר": without it a price per meter reads as the item price.
            'price_note' => self::priceNote($p),
            'needs_options' => self::needsOptions($p) ?: null,
            'reason' => $reasons?->get($p->id),
        ], fn ($v): bool => $v !== null && $v !== ''))->all();
    }

    /**
     * A product the shopper must choose something about (a length, a colour) cannot be added
     * straight to the cart: the store plugin refuses the add without it.
     */
    public static function needsOptions(CatalogProduct $product): bool
    {
        if ($product->type !== 'simple') {
            return true;
        }

        foreach ($product->storeAttributes() as $attribute) {
            if ($attribute['for_variations'] && count($attribute['values']) > 1) {
                return true;
            }
        }

        return false;
    }

    public static function priceNote(CatalogProduct $product): ?string
    {
        $note = trim((string) ($product->payload['meta']['price_text'] ?? ''));

        return $note === '' ? null : mb_substr(strip_tags($note), 0, 40);
    }
}
