<?php

namespace App\Modules\Ai\Contracts;

use RuntimeException;

/** A model call refused because it would pass the monthly spending cap. Nothing was sent. */
final class SpendCapReached extends RuntimeException
{
    public function __construct(
        public readonly float $spentUsd,
        public readonly float $estimatedUsd,
        public readonly float $capUsd,
    ) {
        parent::__construct(__('ai::budget.cap_reached', [
            'spent' => number_format($spentUsd, 2),
            'estimate' => number_format($estimatedUsd, 2),
            'cap' => number_format($capUsd, 2),
        ]));
    }
}
