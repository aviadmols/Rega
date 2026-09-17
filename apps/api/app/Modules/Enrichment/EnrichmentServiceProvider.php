<?php

namespace App\Modules\Enrichment;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Enrichment\Console\EnrichmentCommand;

final class EnrichmentServiceProvider extends ModuleServiceProvider
{
    protected function moduleCommands(): array
    {
        return [
            EnrichmentCommand::class,
        ];
    }
}
