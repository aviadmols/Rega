<?php

namespace App\Modules\Ai\Contracts;

/**
 * The spending cap on the model keys saved in the panel. Code that calls a model with those keys
 * asks first, with its estimate, and records the real cost on its run afterwards
 * (RunContext::usage), which is what later checks add up.
 *
 * Model work done outside the platform and uploaded as answer files costs nothing here.
 */
interface SpendGuard
{
    /** @throws SpendCapReached when this month's cost plus the estimate would pass the cap */
    public function assertCanSpend(float $estimatedUsd): void;

    /** What runs recorded this calendar month, in USD. */
    public function spentThisMonth(): float;

    /** The monthly cap, in USD (setting ai.monthly_spend_cap_usd). */
    public function cap(): float;
}
