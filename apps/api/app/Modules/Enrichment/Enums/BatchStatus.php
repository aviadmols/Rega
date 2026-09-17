<?php

namespace App\Modules\Enrichment\Enums;

enum BatchStatus: string
{
    case AwaitingResults = 'awaiting_results';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __("enrichment::enrichment.batch_statuses.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::AwaitingResults => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'gray',
        };
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
