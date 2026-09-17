<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use Illuminate\Support\Facades\Auth;

/** A person approves or rejects a claim. A person's decision is never replaced by a new reading. */
final class DecideFact
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(EnrichmentFact $fact, bool $approve): EnrichmentFact
    {
        return $this->tenant->run($fact->shop_id, function () use ($fact, $approve): EnrichmentFact {
            $fact->forceFill([
                'status' => $approve ? FactStatus::Approved : FactStatus::Rejected,
                'status_reason' => $approve ? 'person_approved' : 'person_rejected',
                'decided_by' => Auth::id(),
                'decided_at' => now(),
            ])->save();

            return $fact;
        });
    }
}
