<?php

namespace App\Modules\Runs\Contracts;

use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use Closure;

/**
 * The one way any module records what its agents do.
 *
 *   $run = app(RecordsRuns::class)->track(
 *       agent: 'connections.store_checker',
 *       action: 'connections.test',
 *       work: function (RunContext $run) { ...; $run->summary('connections::runs.connected'); },
 *       shopId: $shop->id,
 *   );
 *
 * The run is saved as running before the work starts, so the activity screen shows it live.
 * An exception inside the work marks the run failed and is reported; it is not rethrown, so a
 * button in the admin can always show what happened.
 */
interface RecordsRuns
{
    /**
     * @param  Closure(RunContext): void  $work
     * @param  array<string, mixed>  $input  shown on the run; credential-like keys are redacted
     */
    public function track(
        string $agent,
        string $action,
        Closure $work,
        ?string $shopId = null,
        array $input = [],
        RunTrigger $trigger = RunTrigger::Manual,
        ?string $parentId = null,
    ): Run;
}
