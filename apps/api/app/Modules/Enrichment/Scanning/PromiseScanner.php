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

    /**
     * "100%" in a store's text is usually a boast, not a material: 100% sealing, 100% power
     * transfer, 100% satisfaction. Only a word from this list is read as what the thing is made
     * of, so the widget never tells a shopper the product is "100% protection".
     *
     * @var list<string>
     */
    private const MATERIALS = [
        'כותנה', 'פשתן', 'צמר', 'משי', 'עור', 'במבוק', 'נחושת', 'פליז', 'אלומיניום', 'נירוסטה',
        'פלדה', 'ברזל', 'אבץ', 'טיטניום', 'עץ', 'אורן', 'אלון', 'בוק', 'טיק', 'מלמין',
        'מיקרופייבר', 'פוליאסטר', 'פוליאוריטן', 'פוליאוריתן', 'אקריל', 'אקרילי', 'סיליקון',
        'גומי', 'לטקס', 'זכוכית', 'קרמיקה', 'ניילון', 'פוליפרופילן', 'ויסקוזה',
        'cotton', 'linen', 'wool', 'silk', 'leather', 'bamboo', 'copper', 'brass', 'aluminium',
        'aluminum', 'stainless', 'steel', 'iron', 'titanium', 'wood', 'oak', 'pine', 'teak',
        'microfiber', 'microfibre', 'polyester', 'polyurethane', 'acrylic', 'silicone', 'rubber',
        'latex', 'glass', 'ceramic', 'nylon', 'polypropylene', 'viscose',
    ];

    /**
     * "תוצרת" means "made by" as often as "made in", and a store writes "תוצרת Makita" far more
     * often than "תוצרת איטליה". Only a place is read as where the thing was made; a brand is
     * already known from the product's own brand fact.
     *
     * @var list<string>
     */
    private const PLACES = [
        'ישראל', 'אנגליה', 'בריטניה', 'סקוטלנד', 'אירלנד', 'איטליה', 'גרמניה', 'צרפת', 'ספרד',
        'פורטוגל', 'הולנד', 'בלגיה', 'שוויץ', 'אוסטריה', 'פולין', 'צ׳כיה', 'סלובניה', 'רומניה',
        'הונגריה', 'יוון', 'טורקיה', 'סין', 'יפן', 'קוריאה', 'טייוואן', 'תאילנד', 'וייטנאם',
        'הודו', 'אמריקה', 'קנדה', 'מקסיקו', 'ברזיל', 'שוודיה', 'פינלנד', 'דנמרק', 'נורווגיה',
        'אוסטרליה', 'אוקראינה', 'רוסיה', 'סלובקיה', 'בולגריה', 'ליטא', 'לטביה', 'אסטוניה',
        'israel', 'england', 'britain', 'scotland', 'ireland', 'italy', 'germany', 'france',
        'spain', 'portugal', 'netherlands', 'holland', 'belgium', 'switzerland', 'austria',
        'poland', 'czechia', 'slovenia', 'romania', 'hungary', 'greece', 'turkey', 'china',
        'japan', 'korea', 'taiwan', 'thailand', 'vietnam', 'india', 'usa', 'america', 'canada',
        'mexico', 'brazil', 'sweden', 'finland', 'denmark', 'norway', 'australia', 'ukraine',
    ];

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

                    if (! self::detailFits($key, $detail, $sentence)) {
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
     * A sentence about what the product acts on, not what it is: a stripper that removes 100%
     * acrylic finishes is not made of acrylic.
     */
    private const ACTS_ON = '/מסיר|להסיר|מנקה|לניקוי|מוריד|ממיס|מתאים\s+ל|מיועד\s+ל|removes?|strips?|cleans?|dissolves?/u';

    /** A material must be a material and a place must be a place; anything else is a boast. */
    private static function detailFits(string $key, ?string $detail, string $sentence): bool
    {
        $word = mb_strtolower((string) $detail);

        return match ($key) {
            'pure_material' => in_array($word, array_map('mb_strtolower', self::MATERIALS), true)
                && preg_match(self::ACTS_ON, $sentence) !== 1,
            'made_in' => in_array($word, array_map('mb_strtolower', self::PLACES), true),
            default => true,
        };
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
        // A making word is required: a store that sells materials for hand-built projects is not
        // telling a shopper that this product was made by hand.
        'handmade' => [
            '/(?:מיוצר|מיוצרת|עשוי|עשויה|נוצר|נוצרה|מעוצב|מעוצבת|נבנה|נבנתה)[^.]{0,20}?(?:בעבודת|עבודת)\s+יד/u',
            '/(?:עשוי|עשויה|מיוצר|מיוצרת)\s+ביד/u',
            '/מלאכת\s+יד/u',
            '/hand[\- ]?made|handcrafted/iu',
        ],
        'pure_material' => [
            '/100%\s*(?<d>[\p{L}]{2,20})/u',
            '/(?<d>[\p{L}]{2,20})\s*100%/u',
        ],
        'made_in' => [
            '/(?:תוצרת|מיוצר\s+ב|מיוצרת\s+ב)\s*(?<d>[\p{L}]{3,20})/u',
            '/made\s+in\s+(?<d>[\p{L}]{3,20})/iu',
        ],
    ];
}
