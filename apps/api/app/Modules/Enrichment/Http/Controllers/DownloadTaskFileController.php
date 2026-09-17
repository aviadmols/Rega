<?php

namespace App\Modules\Enrichment\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Support\TaskFile;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a batch as a JSON Lines file to operators, streamed so a large batch never sits in
 * memory. Guests go to the operator login and come back here.
 */
final class DownloadTaskFileController
{
    public function __invoke(Request $request, TenantContext $tenant, string $batch): StreamedResponse|RedirectResponse
    {
        $panel = Filament::getPanel('operator');
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest($panel->getLoginUrl());
        }

        abort_unless($user instanceof FilamentUser && $user->canAccessPanel($panel), 403);

        /** @var EnrichmentBatch|null $model */
        $model = $tenant->runUnscoped(fn () => EnrichmentBatch::query()->find($batch));

        abort_if($model === null, 404);

        return response()->streamDownload(function () use ($tenant, $model): void {
            $tenant->run($model->shop_id, function () use ($model): void {
                foreach (TaskFile::lines($model) as $line) {
                    echo $line;
                }
            });
        }, $model->fileName(), [
            'Content-Type' => 'application/x-ndjson; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
