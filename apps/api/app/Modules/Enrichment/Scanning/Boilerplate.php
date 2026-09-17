<?php

namespace App\Modules\Enrichment\Scanning;

/**
 * Finds text a store pastes into many products: "Makita is an international company...",
 * "a huge selection of Stanley tools with delivery". It says nothing about the product in front
 * of the model and misleads it ("professional" describes the brand, not this saw), so code
 * removes it before a model reads anything.
 */
final class Boilerplate
{
    /** Short repeated lines ("שנה אחריות") are often real product facts, so only long ones count. */
    public const MIN_LINE_CHARS = 40;

    public const MIN_PRODUCTS = 4;

    public const MIN_SHARE = 0.03;

    /**
     * @param  iterable<list<string>>  $productSections  each product's text sections
     * @return list<string> normalized lines to drop
     */
    public static function find(iterable $productSections): array
    {
        $counts = [];
        $products = 0;

        foreach ($productSections as $sections) {
            $products++;
            $seen = [];

            foreach ($sections as $section) {
                foreach (TextCondenser::lineKeys($section) as $key) {
                    if (mb_strlen($key) >= self::MIN_LINE_CHARS) {
                        $seen[$key] = true;
                    }
                }
            }

            foreach (array_keys($seen) as $key) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        $threshold = max(self::MIN_PRODUCTS, (int) ceil($products * self::MIN_SHARE));
        $lines = array_keys(array_filter($counts, fn (int $count): bool => $count >= $threshold));
        sort($lines, SORT_STRING);

        return array_values(array_map('strval', $lines));
    }
}
