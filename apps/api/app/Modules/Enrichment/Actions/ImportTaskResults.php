<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Enrichment\Enums\BatchStatus;
use App\Modules\Enrichment\Enums\ItemStatus;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;
use App\Modules\Enrichment\Support\TaskFile;
use App\Modules\Enrichment\Tasks\TaskRegistry;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Facades\DB;

/**
 * Reads a model's answers into a batch. Every answer goes through the task's checks; nothing
 * a model says is saved unchecked. Answers can arrive in several files: each import handles
 * what is still pending.
 */
final class ImportTaskResults
{
    public const ACTION = 'enrichment.import_results';

    public const PROVIDER = 'external';

    private const MAX_PROBLEM_SAMPLES = 30;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly TaskRegistry $tasks,
    ) {}

    public function handle(EnrichmentBatch $batch, string $contents, ?string $model = null): Run
    {
        return $this->runs->track(
            agent: $batch->task->agent(),
            action: self::ACTION,
            shopId: $batch->shop_id,
            input: ['batch_id' => $batch->id, 'bytes' => strlen($contents), 'model' => $model],
            work: fn (RunContext $run) => $this->tenant->run($batch->shop_id, fn () => $this->import($run, $batch, $contents, $model)),
        );
    }

    private function import(RunContext $run, EnrichmentBatch $batch, string $contents, ?string $model): void
    {
        $parsed = TaskFile::parseResults($contents);
        $header = $parsed['header'] ?? [];

        // Request IDs carry a hash of what was asked, so answers made for another batch with the
        // same requests (the same products, text and prompt) are valid here too. The batch ID in
        // the header is only a hint; what decides is whether the request IDs match.
        $otherBatch = isset($header['batch_id']) && $header['batch_id'] !== $batch->id;

        $model = trim((string) ($model ?: ($header['model'] ?? ''))) ?: 'unknown';
        $task = $this->tasks->for($batch->task);
        $items = $batch->items()->get()->keyBy('custom_id');

        $counts = ['answers' => count($parsed['results']), 'applied' => 0, 'rejected' => 0, 'stale' => 0, 'already_done' => 0, 'unknown' => 0, 'facts' => 0, 'problems' => 0];
        $problems = $parsed['problems'];

        foreach ($parsed['results'] as $customId => $output) {
            /** @var EnrichmentBatchItem|null $item */
            $item = $items->get($customId);

            if ($item === null) {
                $counts['unknown']++;
                $problems[] = "{$customId}:unknown_custom_id";

                continue;
            }

            if ($item->status !== ItemStatus::Pending) {
                $counts['already_done']++;

                continue;
            }

            DB::transaction(function () use ($task, $batch, $item, $output, $model, &$counts, &$problems): void {
                if ($task->isStale($batch, $item)) {
                    $item->forceFill(['status' => ItemStatus::Stale, 'result' => $output])->save();
                    $counts['stale']++;

                    return;
                }

                $outcome = $task->apply($batch, $item, $output, $model);

                $item->forceFill([
                    'status' => $outcome->status,
                    'result' => $output,
                    'problems' => $outcome->problems === [] ? null : $outcome->problems,
                ])->save();

                $counts[$outcome->status === ItemStatus::Applied ? 'applied' : 'rejected']++;
                $counts['facts'] += $outcome->accepted;
                $counts['problems'] += count($outcome->problems);

                foreach ($outcome->problems as $problem) {
                    $problems[] = "{$item->custom_id}:{$problem}";
                }
            });
        }

        $pending = $batch->items()->where('status', ItemStatus::Pending)->count();

        $batch->forceFill([
            'result_count' => $batch->items()->where('status', '!=', ItemStatus::Pending)->count(),
            'accepted_count' => $batch->accepted_count + $counts['facts'],
            'rejected_count' => $batch->rejected_count + $counts['problems'],
            'model' => mb_substr($model, 0, 120),
            'import_run_id' => $run->runId,
            'status' => $pending === 0 ? BatchStatus::Completed : BatchStatus::AwaitingResults,
            'completed_at' => $pending === 0 ? now() : null,
        ])->save();

        if ($otherBatch && $counts['answers'] > 0 && $counts['unknown'] === $counts['answers']) {
            $run->output(['answers' => $counts['answers'], 'unknown' => $counts['unknown']])->fail('enrichment::runs.other_batch');

            return;
        }

        $run->usage(self::PROVIDER, $model)
            ->output($counts + ['pending' => $pending, 'from_other_batch' => $otherBatch, 'problem_samples' => array_slice($problems, 0, self::MAX_PROBLEM_SAMPLES)])
            ->summary('enrichment::runs.results_imported', [
                'answers' => number_format($counts['answers']),
                'facts' => number_format($counts['facts']),
                'problems' => number_format($counts['problems'] + $counts['rejected'] + $counts['unknown']),
                'pending' => number_format($pending),
                'model' => $model,
            ]);

        if ($counts['answers'] === 0) {
            $run->fail('enrichment::runs.no_answers');
        }
    }
}
