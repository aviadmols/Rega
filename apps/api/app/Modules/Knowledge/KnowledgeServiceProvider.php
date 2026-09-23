<?php

namespace App\Modules\Knowledge;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Knowledge\Console\KnowledgeCommand;
use Illuminate\Console\Scheduling\Schedule;

final class KnowledgeServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        // Last of the night, after the catalogue was synced and the scores computed, so the
        // snapshot describes the day that just finished rather than the one before it.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('knowledge snapshot --all')
                ->dailyAt('05:10')
                ->timezone('Asia/Jerusalem')
                ->name('knowledge:snapshot-daily')
                ->withoutOverlapping()
                ->onOneServer();
        });
    }

    protected function moduleCommands(): array
    {
        return [
            KnowledgeCommand::class,
        ];
    }
}
