<?php

namespace App\Modules\Enrichment\Support;

/**
 * The marker words the article reader looks for, kept as data rather than in the reader.
 *
 * This is the surface the audit is allowed to improve. When a model finds that code missed a
 * takeaway because a site words its conclusions differently, the fix is a new marker here — a
 * new version of a ruleset, reviewed and published by a person — and never a change to the
 * program. That keeps every improvement readable, reversible and attributable.
 *
 * @see ArticleReader
 */
final class ContentRules
{
    public const VERSION = 2;

    /**
     * A ruleset a shop published before a list existed still has to be readable, so the lists it
     * predates come from the defaults rather than being empty.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public static function withDefaults(array $rules): array
    {
        return $rules + self::defaults();
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'version' => self::VERSION,
            // Lines that open this way are the article telling the reader what to keep.
            'takeaway_markers' => [
                'חשוב לדעת', 'שימו לב', 'לסיכום', 'בשורה התחתונה', 'הטיפ', 'טיפ', 'כדאי',
                'המלצה', 'שורה תחתונה', 'זכרו', 'אל תשכחו',
                'Tip', 'Note', 'Remember', 'In short', 'Bottom line', 'Key takeaway',
            ],
            // A Hebrew article rarely opens a line with its conclusion; it arrives mid-sentence,
            // after the setup. These are looked for anywhere in a line, and what follows one of
            // them to the end of the line is the takeaway.
            'takeaway_phrases' => [
                'ההמלצה היא', 'חשוב לציין', 'כדאי לזכור', 'המשמעות היא', 'השורה התחתונה היא',
                'the recommendation is', 'it is important to note', 'keep in mind',
            ],
            // What follows one of these is who the article is for.
            'audience_markers' => [
                'מתאים ל', 'מיועד ל', 'המדריך הזה ל', 'כתבה זו ל', 'למי זה מתאים',
                'Suited to', 'Written for', 'This guide is for', 'Best for',
            ],
        ];
    }
}
