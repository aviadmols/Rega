<?php

namespace App\Modules\Enrichment\Enums;

/** Why a product is shown with another one. Each kind has its own place in the widget. */
enum RelationKind: string
{
    /** Goes with it: a battery for a body-only tool, oil for outdoor wood, screws for decking. */
    case Complement = 'complement';

    /** The same product in another size or version. */
    case Family = 'family';

    /** Another product that does the same job, at a similar price. */
    case Alternative = 'alternative';

    public function label(): string
    {
        return __("enrichment::enrichment.relation_kinds.{$this->value}");
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_combine(
            array_map(fn (self $k): string => $k->value, self::cases()),
            array_map(fn (self $k): string => $k->label(), self::cases()),
        );
    }
}
