<?php

namespace App\Modules\Enrichment\Tasks;

use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;

/**
 * One kind of agent work. A task knows three things: which requests to send, whether an answer
 * still matches its subject, and how to turn an answer into checked facts.
 *
 * How the requests reach a model (a downloaded file for an outside model, a provider batch
 * API later) is not the task's concern.
 */
interface AgentTask
{
    public function type(): TaskType;

    /** @return iterable<TaskRequest> at most $limit requests for the batch's shop and scope */
    public function requests(EnrichmentBatch $batch, int $limit): iterable;

    /** The subject changed or disappeared since the request was built. */
    public function isStale(EnrichmentBatch $batch, EnrichmentBatchItem $item): bool;

    /** @param array<string, mixed> $output the model's answer, already decoded */
    public function apply(EnrichmentBatch $batch, EnrichmentBatchItem $item, array $output, string $model): ItemOutcome;
}
