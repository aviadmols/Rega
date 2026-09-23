<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;

/**
 * The products a model has not read yet, read tonight — a fixed number of them.
 *
 * Model reading was the one step that needed a person to carry a file to an agent and carry the
 * answers back, which meant in practice it happened once and then never again. A shop that gains
 * products gains products nobody has read.
 *
 * So it asks directly, for as many products as the shop allows a night, and stops on the month's
 * cap rather than on a person's attention. Everything else is unchanged: the same prompt, the
 * same request, and every answer checked in code before a single fact is written.
 *
 * A night reads a bounded number on purpose. It keeps the spend predictable, it spreads a large
 * catalogue over a week or two instead of one expensive evening, and it means a prompt that turns
 * out to be wrong costs one night rather than the whole catalogue.
 */
final class RunNightlyModelReading
{
    public const AGENT = 'enrichment.nightly_model';

    public const ACTION = 'enrichment.nightly_model';

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly CreateTaskFile $batches,
        private readonly AnswerBatchWithModel $answers,
    ) {}

    public function handle(string $shopId): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            work: function (RunContext $run) use ($shopId): void {
                $limit = (int) Settings::get('enrichment.nightly_model_requests', $shopId);

                if ($limit === 0) {
                    $run->output(['asked' => 0])->summary('enrichment::runs.nightly_model_off');

                    return;
                }

                $model = (string) Settings::get('assistant.scope_model');

                // A shop's catalogue is divided between several vocabularies, and a product can
                // only be read against the one its branch belongs to. Taking the first would mean
                // reading one branch until it was finished and never touching the others, so each
                // is tried in turn until one still has something unread.
                $vocabularies = $this->tenant->run($shopId, fn () => EnrichmentVocabulary::query()
                    ->where('active', true)->orderBy('key')->get());
                $batch = null;

                foreach ($vocabularies as $vocabulary) {
                    $batch = $this->batches->handle($shopId, TaskType::ProductExtraction, $vocabulary->id, limit: $limit)['batch'] ?? null;

                    if ($batch !== null) {
                        break;
                    }
                }

                // Nothing to read is the ordinary case for a shop that has caught up.
                if ($batch === null) {
                    $run->output(['asked' => 0, 'vocabularies' => $vocabularies->count()])
                        ->summary('enrichment::runs.nightly_model_nothing');

                    return;
                }

                $counts = $this->tenant->run($shopId, fn (): array => $this->answers->handle($run, $batch, $model, $limit));

                $run->output($counts + ['batch' => $batch->id, 'model' => $model])
                    ->summary('enrichment::runs.nightly_model', [
                        'asked' => number_format($counts['asked']),
                        'applied' => number_format($counts['applied']),
                        'rejected' => number_format($counts['rejected']),
                    ]);
            },
        );
    }
}
