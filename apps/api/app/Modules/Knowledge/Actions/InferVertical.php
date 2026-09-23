<?php

namespace App\Modules\Knowledge\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Enums\Vertical;
use App\Modules\Tenancy\Models\Shop;

/**
 * Which trade a shop is in, read from its own catalogue.
 *
 * Nobody is asked. A shop owner signing up does not know what a "vertical" is, and a field they
 * have to guess at is a field that will be wrong. The categories and product titles they already
 * wrote say it plainly enough: a shop whose catalogue is full of drills and timber is a hardware
 * shop, and the confidence is simply how much of the catalogue said so.
 *
 * Someone who disagrees locks it, and this stops arguing.
 */
final class InferVertical
{
    /** Below this share of the catalogue, the catalogue has not said anything. */
    private const MIN_CONFIDENCE = 15;

    /** Enough of a catalogue to judge by; a shop with three products has not shown its hand. */
    private const MIN_PRODUCTS = 10;

    public function __construct(private readonly TenantContext $tenant) {}

    /** @return array{vertical: ?string, confidence: int, locked: bool} */
    public function handle(Shop $shop): array
    {
        if ($shop->vertical_locked) {
            return ['vertical' => $shop->vertical, 'confidence' => (int) $shop->vertical_confidence, 'locked' => true];
        }

        [$best, $confidence] = $this->tenant->run($shop->id, fn (): array => $this->read());

        $shop->forceFill([
            'vertical' => $confidence >= self::MIN_CONFIDENCE ? $best?->value : null,
            'vertical_confidence' => $confidence,
        ])->save();

        return ['vertical' => $shop->vertical, 'confidence' => $confidence, 'locked' => false];
    }

    /** @return array{0: ?Vertical, 1: int} */
    private function read(): array
    {
        $titles = CatalogProduct::query()->whereNull('removed_at')->pluck('title');
        $categories = CatalogCategory::query()->pluck('name');

        if ($titles->count() < self::MIN_PRODUCTS) {
            return [null, 0];
        }

        $text = $titles->concat($categories)->implode(' | ');
        $best = null;
        $bestShare = 0;

        foreach (Vertical::all() as $vertical) {
            // How many of the shop's own lines this trade's plainest words appear in.
            $hits = 0;

            foreach ($titles as $title) {
                foreach ($vertical->markers() as $marker) {
                    if (mb_stripos((string) $title, $marker) !== false) {
                        $hits++;

                        break;
                    }
                }
            }

            // A category name is worth more than a product title: there are fewer of them and a
            // shop chooses them deliberately.
            foreach ($categories as $name) {
                foreach ($vertical->markers() as $marker) {
                    if (mb_stripos((string) $name, $marker) !== false) {
                        $hits += 3;

                        break;
                    }
                }
            }

            $share = (int) round(100 * min(1.0, $hits / max(1, $titles->count())));

            if ($share > $bestShare) {
                $best = $vertical;
                $bestShare = $share;
            }
        }

        unset($text);

        return [$best, $bestShare];
    }
}
