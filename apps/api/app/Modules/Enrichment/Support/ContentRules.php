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
    public const VERSION = 1;

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
            // What follows one of these is who the article is for.
            'audience_markers' => [
                'מתאים ל', 'מיועד ל', 'המדריך הזה ל', 'כתבה זו ל', 'למי זה מתאים',
                'Suited to', 'Written for', 'This guide is for', 'Best for',
            ],
        ];
    }
}
