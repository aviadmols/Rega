<?php

namespace App\Modules\Leads\Support;

use App\Modules\Leads\Enums\LeadGoal;

/**
 * A call to action written from what the page is actually about.
 *
 * Every shop that has ever added a banner has added the same banner to every page, and readers
 * have learned to look past it. What makes one worth reading is that it knows which page it is
 * on: a piece about choosing a parquet floor should offer help choosing a parquet floor, not
 * "contact us".
 *
 * This is the code layer, and it runs first and always. It costs nothing, it cannot invent
 * anything — every word it uses is either the shop's own offer or a subject lifted from the
 * article — and it means a page has a call to action from the first night, before any model has
 * been asked and whatever the model later makes of it.
 *
 * What it produces is three versions rather than one, because which of them works is not a thing
 * anybody can know by reading them. The learning finds out.
 */
final class CtaComposer
{
    /** Three is enough to learn from and few enough that each gets seen. */
    public const VERSIONS = 3;

    /**
     * Versions for one page, best guess first.
     *
     * @param  array{subject: ?string, audience: ?string, points: int, question: ?string}  $page
     * @param  array<string, mixed>  $rules
     * @return list<array{key: string, headline: string, body: string, source: string}>
     */
    public static function compose(LeadGoal $goal, string $offer, array $page, array $rules, string $locale): array
    {
        $subject = self::trimmed($page['subject'] ?? null);
        $audience = self::trimmed($page['audience'] ?? null);
        $points = max(0, (int) ($page['points'] ?? 0));
        $question = self::trimmed($page['question'] ?? null);

        $candidates = [];

        // A subject that is already a question is already a headline: wrapping it produces
        // "weighing up what to know when choosing a shed? the material matters?", which is two
        // questions and reads like neither.
        if ($subject !== null && self::isAQuestion($subject)) {
            $candidates[] = ['key' => 'question', 'headline' => $subject, 'body' => $offer];
            $subject = null;
        }

        // The strongest shape: name what the page is about, then offer help with that. Only when
        // the subject is short enough to sit inside a sentence — a whole title is not a subject.
        if ($subject !== null && mb_strlen($subject) <= self::LONGEST_SUBJECT) {
            $candidates[] = ['key' => 'subject', 'headline' => self::line('subject', $locale, ['subject' => self::bare($subject)]), 'body' => $offer];
        }

        // The question the piece answers, handed back as an invitation.
        if ($question !== null) {
            $candidates[] = ['key' => 'question', 'headline' => $question, 'body' => $offer];
        }

        // Who the piece is for, when it says so.
        if ($audience !== null) {
            $candidates[] = ['key' => 'audience', 'headline' => self::line('audience', $locale, ['audience' => $audience]), 'body' => $offer];
        }

        // What the reader has just been given, as a reason to want more of it.
        if ($points >= 2) {
            $candidates[] = ['key' => 'points', 'headline' => self::line('points', $locale, ['count' => (string) $points]), 'body' => $offer];
        }

        // Always last, always available: the offer on its own. A page the scan found nothing in
        // still gets something honest rather than nothing.
        $candidates[] = ['key' => 'offer', 'headline' => self::line('goal_'.$goal->value, $locale, []), 'body' => $offer];

        $kept = [];

        $shapes = [];

        foreach ($candidates as $candidate) {
            $candidate['source'] = 'code';

            if (! isset($shapes[$candidate['key']]) && self::allowed($candidate, $rules)) {
                $shapes[$candidate['key']] = true;
                $kept[] = $candidate;
            }

            if (count($kept) >= self::VERSIONS) {
                break;
            }
        }

        return $kept;
    }

    /**
     * Whether a version may be shown to anybody.
     *
     * The same gate the model's versions go through, run on the code's own, because a template
     * filled with a subject lifted from an article can produce a sentence nobody meant either.
     *
     * @param  array{headline: string, body: string}  $cta
     * @param  array<string, mixed>  $rules
     */
    public static function allowed(array $cta, array $rules): bool
    {
        return self::reject($cta, $rules) === null;
    }

    /**
     * Why a version may not be shown, or null when it may.
     *
     * @param  array{headline: string, body: string}  $cta
     * @param  array<string, mixed>  $rules
     */
    public static function reject(array $cta, array $rules): ?string
    {
        $headline = trim($cta['headline']);
        $body = trim($cta['body']);

        if ($headline === '' || $body === '') {
            return 'empty';
        }

        if (mb_strlen($headline) > (int) ($rules['max_headline_chars'] ?? 90)) {
            return 'headline_too_long';
        }

        if (mb_strlen($body) > (int) ($rules['max_body_chars'] ?? 160)) {
            return 'body_too_long';
        }

        $whole = $headline.' '.$body;

        foreach (['promise_patterns' => 'promises_a_result', 'urgency_patterns' => 'invented_urgency', 'forbidden_words' => 'forbidden_word'] as $list => $why) {
            foreach ((array) ($rules[$list] ?? []) as $pattern) {
                if ($pattern !== '' && mb_stripos($whole, (string) $pattern) !== false) {
                    return $why;
                }
            }
        }

        return null;
    }

    /** A subject long enough to be a title is not a subject a sentence can hold. */
    private const LONGEST_SUBJECT = 40;

    private static function isAQuestion(string $text): bool
    {
        return str_ends_with(rtrim($text), '?');
    }

    /** A subject without the punctuation that ended the sentence it was lifted from. */
    private static function bare(string $text): string
    {
        return trim((string) preg_replace('/[.!?,;:־–—\s]+$/u', '', $text));
    }

    private static function line(string $key, string $locale, array $replace): string
    {
        return trim((string) __('leads::cta.'.$key, $replace, $locale));
    }

    private static function trimmed(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
