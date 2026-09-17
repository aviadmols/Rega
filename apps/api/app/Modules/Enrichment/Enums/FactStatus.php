<?php

namespace App\Modules\Enrichment\Enums;

/**
 * Where a claim is on its way to being trusted.
 *
 *   code and a model agree ............................ approved
 *   a model alone, or an ambiguous match .............. awaiting_review (small reviewer model)
 *   the reviewer is unsure ............................ awaiting_escalation (stronger model)
 *   the stronger model is unsure too .................. needs_person
 *   any check says wrong .............................. rejected
 *   a newer reading of the product replaced it ........ superseded
 */
enum FactStatus: string
{
    case Approved = 'approved';
    case AwaitingReview = 'awaiting_review';
    case AwaitingEscalation = 'awaiting_escalation';
    case NeedsPerson = 'needs_person';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function label(): string
    {
        return __("enrichment::enrichment.fact_statuses.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::AwaitingReview, self::AwaitingEscalation => 'warning',
            self::NeedsPerson => 'info',
            self::Rejected => 'danger',
            self::Superseded => 'gray',
        };
    }

    /** @return list<self> statuses a person or a reviewer can still change */
    public static function open(): array
    {
        return [self::AwaitingReview, self::AwaitingEscalation, self::NeedsPerson];
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_combine(
            array_map(fn (self $s): string => $s->value, self::cases()),
            array_map(fn (self $s): string => $s->label(), self::cases()),
        );
    }
}
