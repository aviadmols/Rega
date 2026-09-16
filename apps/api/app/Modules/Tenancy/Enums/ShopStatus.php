<?php

namespace App\Modules\Tenancy\Enums;

enum ShopStatus: string
{
    /** Widget runs, API answers, background work runs. */
    case Active = 'active';

    /** Temporarily off: the API refuses the shop's keys and the widget hides. Data is kept. */
    case Paused = 'paused';

    /** Off until the operator turns it back on. */
    case Disabled = 'disabled';

    public function label(): string
    {
        return __("tenancy::shops.statuses.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Paused => 'warning',
            self::Disabled => 'danger',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_combine(
            array_map(fn (self $s) => $s->value, self::cases()),
            array_map(fn (self $s) => $s->label(), self::cases()),
        );
    }
}
