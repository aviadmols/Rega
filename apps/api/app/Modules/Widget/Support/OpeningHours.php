<?php

namespace App\Modules\Widget\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * When the store team answers, one day at a time.
 *
 * It used to be three settings: one string for "the week", one for Friday and one for Saturday.
 * Nobody could tell from the screen which days "the week" meant, or whether an empty Friday meant
 * closed or the same as the rest. Now every day says for itself, and an empty day is closed.
 *
 * A day is "HH:MM-HH:MM" or empty. Nothing here knows about WhatsApp; it is just a week.
 */
final class OpeningHours
{
    /** Sunday first, the way the week runs where the pilot store is. */
    public const DAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    /** @param array<int, string> $days one entry per day, Sunday first */
    private function __construct(private readonly array $days) {}

    /** @param array<int|string, string|null> $days */
    public static function fromDays(array $days): self
    {
        $clean = [];

        foreach (self::DAYS as $index => $name) {
            $value = trim((string) ($days[$index] ?? $days[$name] ?? ''));
            $clean[$index] = self::parse($value) === null ? '' : $value;
        }

        return new self($clean);
    }

    /** @return list<string> one entry per day, Sunday first, empty where the shop is closed */
    public function toList(): array
    {
        return array_values($this->days);
    }

    public function day(int $index): string
    {
        return $this->days[$index] ?? '';
    }

    /** True when every day is closed, which means the shop never advertises an answer time. */
    public function alwaysClosed(): bool
    {
        return implode('', $this->days) === '';
    }

    /**
     * Whether the team is answering at this moment, in the shop's own timezone. A day that runs
     * past midnight ("22:00-02:00") counts until it ends, on the same day it started.
     */
    public function openAt(DateTimeImmutable $now, string $timezone): bool
    {
        try {
            $local = $now->setTimezone(new DateTimeZone($timezone === '' ? 'UTC' : $timezone));
        } catch (\Exception) {
            $local = $now->setTimezone(new DateTimeZone('UTC'));
        }

        $range = self::parse($this->day((int) $local->format('w')));

        if ($range === null) {
            return false;
        }

        [$from, $until] = $range;
        $minutes = ((int) $local->format('G')) * 60 + (int) $local->format('i');

        return $until > $from
            ? $minutes >= $from && $minutes < $until
            : $minutes >= $from || $minutes < $until;
    }

    /**
     * "09:00-18:00" as minutes from midnight, or null when the text is not a range.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function parse(string $value): ?array
    {
        if (preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $value, $m) !== 1) {
            return null;
        }

        $from = (int) $m[1] * 60 + (int) $m[2];
        $until = (int) $m[3] * 60 + (int) $m[4];

        return $from === $until || (int) $m[1] > 23 || (int) $m[3] > 23 || (int) $m[2] > 59 || (int) $m[4] > 59
            ? null
            : [$from, $until];
    }

    /** "09:00" and "18:00" into "09:00-18:00"; anything incomplete is a closed day. */
    public static function compose(?string $from, ?string $until): string
    {
        $range = trim((string) $from).'-'.trim((string) $until);

        return self::parse($range) === null ? '' : $range;
    }

    /** The two ends of a day, for a form that shows them separately. */
    public static function split(string $value): array
    {
        return self::parse($value) === null
            ? ['from' => null, 'until' => null]
            : ['from' => explode('-', $value)[0], 'until' => explode('-', $value)[1]];
    }
}
