<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Enrichment\Models\EnrichmentRelationRules;
use App\Modules\Enrichment\Relations\RelationRuleSet;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Facades\Auth;

/**
 * Saves a shop's rules for what goes with what as a new version. Rules that fail validation are
 * not saved, and the run lists why.
 */
final class ImportRelationRules
{
    public const ACTION = 'enrichment.import_relation_rules';

    /** Ready-made rule sets any shop can start from. */
    public const TEMPLATES = ['hardware-store'];

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
    ) {}

    /** @return array<string, mixed>|null */
    public static function template(string $name): ?array
    {
        if (! in_array($name, self::TEMPLATES, true)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents(dirname(__DIR__)."/resources/relations/{$name}.json"), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{run: Run, rules: EnrichmentRelationRules|null}
     */
    public function handle(string $shopId, array $data, ?string $author = null): array
    {
        $saved = null;

        $run = $this->runs->track(
            agent: ComputeProductRelations::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            input: ['author' => $author],
            work: function (RunContext $run) use ($shopId, $data, $author, &$saved): void {
                [$rules, $problems] = RelationRuleSet::parse($data);

                if ($rules === null) {
                    $run->output(['problems' => array_slice($problems, 0, 50)])
                        ->fail('enrichment::runs.relation_rules_invalid', ['count' => count($problems)], implode('; ', array_slice($problems, 0, 10)));

                    return;
                }

                $saved = $this->tenant->run($shopId, function () use ($shopId, $rules, $author): EnrichmentRelationRules {
                    $current = EnrichmentRelationRules::query()->where('active', true)->where('definition_hash', $rules->hash())->first();

                    if ($current !== null) {
                        return $current;
                    }

                    $version = ((int) EnrichmentRelationRules::query()->max('version')) + 1;
                    EnrichmentRelationRules::query()->update(['active' => false]);

                    return EnrichmentRelationRules::query()->create([
                        'shop_id' => $shopId,
                        'version' => $version,
                        'definition' => $rules->toArray(),
                        'definition_hash' => $rules->hash(),
                        'author' => $author === null ? null : mb_substr($author, 0, 120),
                        'active' => true,
                        'created_by' => Auth::id(),
                    ]);
                });

                $run->output(['rules_id' => $saved->id, 'version' => $saved->version])
                    ->summary('enrichment::runs.relation_rules_saved', ['version' => $saved->version, 'rules' => count($rules->rules())]);
            },
        );

        return ['run' => $run, 'rules' => $saved];
    }
}
