<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Enrichment\Models\EnrichmentContentRules;
use App\Modules\Enrichment\Models\EnrichmentRuleProposal;
use Illuminate\Support\Facades\DB;

/**
 * A person turns an approved proposal into the rules the reader uses.
 *
 * This is the only way the reading ever changes. It adds a version rather than editing one, so
 * what the reader was doing before is still there to go back to, and the proposal that caused it
 * keeps pointing at the articles that prompted it.
 */
final class PublishContentRules
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @return EnrichmentContentRules|null null when the proposal is not one a person may publish */
    public function handle(EnrichmentRuleProposal $proposal, ?int $userId): ?EnrichmentContentRules
    {
        if (! $proposal->publishable()) {
            return null;
        }

        return $this->tenant->run($proposal->shop_id, function () use ($proposal, $userId): EnrichmentContentRules {
            return DB::transaction(function () use ($proposal, $userId): EnrichmentContentRules {
                $current = EnrichmentContentRules::inForce($proposal->shop_id);

                $rules = $current;
                $rules['takeaway_markers'] = self::added($current, $proposal, 'takeaway_markers');
                $rules['audience_markers'] = self::added($current, $proposal, 'audience_markers');
                $rules['version'] = (int) ($current['version'] ?? 1) + 1;

                EnrichmentContentRules::query()->where('shop_id', $proposal->shop_id)->update(['active' => false]);

                $published = EnrichmentContentRules::query()->create([
                    'shop_id' => $proposal->shop_id,
                    'version' => $rules['version'],
                    'rules' => $rules,
                    'rules_hash' => hash('sha256', (string) json_encode($rules, JSON_UNESCAPED_UNICODE)),
                    'author' => 'audit',
                    'active' => true,
                    'created_by' => $userId,
                ]);

                $proposal->forceFill([
                    'status' => EnrichmentRuleProposal::PUBLISHED,
                    'decided_by' => $userId,
                    'decided_at' => now(),
                ])->save();

                return $published;
            });
        });
    }

    /** A person says no. The proposal stays, with who said it and when. */
    public function discard(EnrichmentRuleProposal $proposal, ?int $userId): void
    {
        $this->tenant->run($proposal->shop_id, fn () => $proposal->forceFill([
            'status' => EnrichmentRuleProposal::DISCARDED,
            'decided_by' => $userId,
            'decided_at' => now(),
        ])->save());
    }

    /**
     * Markers are only ever added, never removed or reworded, so a published version can only
     * ever find more than the one before it.
     *
     * @param  array<string, mixed>  $current
     * @return list<string>
     */
    private static function added(array $current, EnrichmentRuleProposal $proposal, string $list): array
    {
        return array_values(array_unique(array_merge(
            (array) ($current[$list] ?? []),
            (array) (($proposal->proposed ?? [])[$list] ?? []),
        )));
    }
}
