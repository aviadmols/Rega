<?php

namespace App\Modules\Leads\Support;

/**
 * What a call to action may never say, kept as data rather than in the writer.
 *
 * A model asked to sell will promise, and the pages this runs on are about money, health and
 * trades where a promise is a liability before it is a lie. So the refusal is in code and not in
 * a prompt: a prompt can be talked round, a regular expression cannot.
 *
 * This is also the surface the audit is allowed to improve, exactly like the reading rules — when
 * a shop's writer keeps producing something that should not be said, the fix is a new pattern
 * here, in a new version, and never a change to the program.
 */
final class LeadRules
{
    public const VERSION = 1;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'version' => self::VERSION,

            // A result nobody can promise. These are matched anywhere in the line.
            'promise_patterns' => [
                'מובטח', 'הבטחה', 'ללא סיכון', 'בלי סיכון', 'תרוויח', 'תרוויחו', 'רווח מובטח',
                'תשואה מובטחת', 'החזר מובטח', 'בטוח ש', 'אנחנו מבטיחים', '100% הצלחה',
                'guaranteed', 'risk-free', 'no risk', 'you will earn', 'promised return',
            ],

            // Pressure invented by the writer rather than set by the shop.
            'urgency_patterns' => [
                'רק היום', 'נותרו מקומות', 'מקומות אחרונים', 'ההצעה מסתיימת', 'מהר לפני',
                'אל תפספסו', 'הזדמנות אחרונה', 'רק עכשיו',
                'today only', 'last chance', 'act now', 'limited spots', 'hurry',
            ],

            // Words a reader should not have to meet on the way to leaving a phone number.
            'forbidden_words' => [
                'חינם לחלוטין', 'ללא עלות כלל', 'סודי', 'טריק', 'פטנט',
                'secret', 'trick', 'hack',
            ],

            // A call to action longer than this is a paragraph, and nobody reads it.
            'max_headline_chars' => 90,
            'max_body_chars' => 160,
        ];
    }

    /**
     * A ruleset a shop published before a list existed still has to be readable.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public static function withDefaults(array $rules): array
    {
        return $rules + self::defaults();
    }
}
