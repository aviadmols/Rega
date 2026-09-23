<?php

namespace App\Modules\Enrichment;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Enrichment\Actions\RereadPage;
use App\Modules\Enrichment\Console\EnrichmentCommand;
use App\Modules\Enrichment\Contracts\RereadsPages;

final class EnrichmentServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        // Another module may ask for one page to be read again; it gets the code readers only.
        $this->app->bind(RereadsPages::class, RereadPage::class);
    }

    protected function moduleCommands(): array
    {
        return [
            EnrichmentCommand::class,
        ];
    }
}
