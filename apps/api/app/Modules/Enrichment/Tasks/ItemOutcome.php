<?php

namespace App\Modules\Enrichment\Tasks;

use App\Modules\Enrichment\Enums\ItemStatus;

final class ItemOutcome
{
    /**
     * @param  list<string>  $problems  short codes with the part of the answer they refer to
     */
    public function __construct(
        public readonly ItemStatus $status,
        public readonly int $accepted = 0,
        public readonly int $rejected = 0,
        public readonly array $problems = [],
    ) {}

    /** @param list<string> $problems */
    public static function rejected(array $problems): self
    {
        return new self(ItemStatus::Rejected, 0, 0, $problems);
    }
}
