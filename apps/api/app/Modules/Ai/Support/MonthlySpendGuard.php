<?php

namespace App\Modules\Ai\Support;

use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Runs\Models\Run;

/** Adds up the cost runs recorded since the start of the calendar month (UTC), for every shop. */
final class MonthlySpendGuard implements SpendGuard
{
    public function assertCanSpend(float $estimatedUsd): void
    {
        $spent = $this->spentThisMonth();
        $cap = $this->cap();
        $estimate = max(0.0, $estimatedUsd);

        if ($spent + $estimate > $cap) {
            throw new SpendCapReached($spent, $estimate, $cap);
        }
    }

    public function spentThisMonth(): float
    {
        return (float) Run::query()
            ->where('created_at', '>=', now()->utc()->startOfMonth())
            ->whereNotNull('cost_usd')
            ->sum('cost_usd');
    }

    public function cap(): float
    {
        return (float) Settings::get('ai.monthly_spend_cap_usd');
    }
}
