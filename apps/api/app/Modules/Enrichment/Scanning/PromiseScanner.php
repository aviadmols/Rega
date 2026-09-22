<?php

namespace App\Modules\Enrichment\Scanning;

/**
 * What a shop promises, and what a product is made of, read in code from the store's own words.
 *
 * Two sources, two scopes. The shop's pages — the terms, the shipping page, the returns page —
 * say things that help a shopper decide: a refund within fourteen days, free delivery over a
 * sum, two years of warranty. A product's own text says what it is: hand made, all cotton, made
 * somewhere. Both are worth showing next to the product, and neither may be invented.
 *
 * A promise is a key, the detail exactly as the store wrote it ("14 ימים", "כותנה"), and the
 * sentence it came from. The detail is always a piece of that sentence, so no number and no
 * material can appear that the store did not write. Nothing here calls a model: the same page
 * gives the same promises every time.
 */
final class PromiseScanner
{
    /** Longest sentence worth keeping as a quote: past this the page is describing, not promising. */
    private const MAX_QUOTE = 220;

    /** Words that follow "100%" without saying what the thing is made of. */
    private const NOT_A_MATERIAL = ['מהמחיר', 'מהסכום', 'מהערך', 'מהכסף', 'החזר', 'שביעות', 'refund', 'money', 'back', 'satisfaction', 'guarantee', 'guaranteed', 'secure'];

    /**
     * What the shop promises, from one page's text.
     *
     * @return list<array{key: string, detail: string|null, quote: string}>
     */
    public static function shop(string $text): array
    {
        return self::scan($text, self::SHOP_PATTERNS);
    }

    /**
     * What the product is made of and how, from its own text.
     *
     * @return list<array{key: string, detail: string|null, quote: string}>
     */
    public static function product(string $text): array
    {
        return self::scan($text, self::PRODUCT_PATTERNS);
    }

    /**
     * @param  array<string, list<string>>  $patterns
     * @return list<array{key: string, detail: string|null, quote: string}>
     */
    private static function scan(string $text, array $patterns): array
    {
        $found = [];

        foreach (self::sentences($text) as $sentence) {
            foreach ($patterns as $key => $alternatives) {
                if (isset($found[$key])) {
                    continue;
                }

                foreach ($alternatives as $pattern) {
                    if (preg_match($pattern, $sentence, $matches) !== 1) {
                        continue;
                    }

                    $detail = trim(preg_replace('/\s+/u', ' ', (string) ($matches['d'] ?? '')) ?? '');
                    $detail = $detail === '' ? null : $detail;

                    if ($key === 'pure_material' && $detail !== null && in_array($detail, self::NOT_A_MATERIAL, true)) {
                        continue;
                    }

                    $found[$key] = ['key' => $key, 'detail' => $detail, 'quote' => $sentence];
                    break;
                }
            }
        }

        return array_values($found);
    }

    /**
     * The store's text as sentences, short enough to quote. A page is mostly headings and list
     * items, so a line break ends a sentence just as a full stop does.
     *
     * @return list<string>
     */
    private static function sentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?;])\s+|\R+|\s+[•|]\s+/u', TextNormalizer::clean($text)) ?: [];
        $sentences = [];

        foreach ($parts as $part) {
            $part = trim(preg_replace('/\s+/u', ' ', (string) $part) ?? '');

            if ($part !== '' && mb_strlen($part) <= self::MAX_QUOTE) {
                $sentences[] = $part;
            }
        }

        return $sentences;
    }

    /**
     * The promises worth looking for, in Hebrew and in English. The detail group is named "d"
     * and always spans a run of the sentence, number and unit together.
     *
     * @var array<string, list<string>>
     */
    private const SHOP_PATTERNS = [
        // "Full refund within 14 days", "returns up to 30 days".
        'returns' => [
            // The shop may say it as a verb ("ניתן להחזיר"), as a noun, or after the window.
            '/(?:להחזיר|מחזירים?|להחליף|החזרה|החזרת|החזר|ביטול)[^.]{0,60}?(?<d>\d{1,3}\s*(?:ימים|יום))/u',
            '/(?<d>\d{1,3}\s*(?:ימים|יום))[^.]{0,40}?(?:להחזיר|להחזרה|להחזר|לביטול|החזר)/u',
            '/(?:return|refund)[^.]{0,60}?(?<d>\d{1,3}\s*days?)/iu',
        ],
        // "Free shipping over 500", or free shipping with no condition at all.
        'free_shipping' => [
            '/משלוח\s*חינם[^.]{0,40}?(?:מעל|החל\s*מ[־\-]?)\s*(?<d>[₪$]?\s?[\d,]{2,7})/u',
            '/free\s+(?:shipping|delivery)[^.]{0,40}?(?:over|above|from)\s*(?<d>[$₪]?\s?[\d,]{2,7})/iu',
            '/משלוח\s*חינם/u',
            '/free\s+(?:shipping|delivery)/iu',
        ],
        // "Two years of warranty", "12 months warranty".
        'warranty' => [
            '/אחריות[^.]{0,40}?(?<d>\d{1,3}\s*(?:שנים|שנה|חודשים|חודש))/u',
            '/(?<d>\d{1,3}\s*(?:שנות|שנים|שנה|חודשי|חודשים))\s*אחריות/u',
            '/(?<d>\d{1,3}[\- ]?(?:year|month)s?)\s+warranty/iu',
        ],
        // "Delivery within 3 business days".
        'delivery' => [
            '/(?:אספקה|משלוח|הזמנה|שילוח)[^.]{0,40}?(?:תוך|עד)\s*(?<d>\d{1,2}\s*(?:ימי\s*עסקים|ימים|יום))/u',
            '/(?:delivery|dispatch|ships?)[^.]{0,40}?(?:within|in)\s*(?<d>\d{1,2}\s*(?:business\s+)?days?)/iu',
        ],
        // "Up to 12 interest-free payments".
        'payments' => [
            '/(?:עד\s*)?(?<d>\d{1,2})\s*תשלומים/u',
            '/(?:up\s*to\s*)?(?<d>\d{1,2})\s*interest[\- ]free\s+(?:payments|installments)/iu',
        ],
    ];

    /**
     * What a product's own text says it is. The captured word travels with the promise, so
     * "100% cotton" can never become "100% wool".
     *
     * @var array<string, list<string>>
     */
    private const PRODUCT_PATTERNS = [
        'handmade' => [
            '/(?:עבודת|בעבודת)\s+יד/u',
            '/עשוי\s+ביד/u',
            '/hand[\- ]?made|handcrafted/iu',
        ],
        'pure_material' => [
            '/100%\s*(?<d>[\p{L}]{3,20})/u',
            '/(?<d>[\p{L}]{3,20})\s*100%/u',
        ],
        'made_in' => [
            '/תוצרת\s+(?<d>[\p{L}]{3,20})/u',
            '/made\s+in\s+(?<d>[\p{L}]{3,20})/iu',
        ],
    ];
}
