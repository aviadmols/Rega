<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Jobs\SyncCatalogJob;
use App\Modules\Connections\Models\StoreConnection;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Route;

/**
 * "Sync catalog": queues a full read of one shop's store. The worker does the reading; the run
 * appears in the agent activity log and updates live.
 */
final class SyncCatalogAction
{
    private const RUNS_ROUTE = 'filament.operator.resources.runs.index';

    public static function make(): Action
    {
        return Action::make('sync_catalog')
            ->label(__('catalog::catalog.actions.sync'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->modalDescription(__('catalog::catalog.actions.sync_help'))
            ->schema([
                Select::make('shop_id')
                    ->label(__('catalog::catalog.fields.shop'))
                    ->options(fn (): array => StoreConnection::query()
                        ->with('shop')
                        ->get()
                        ->mapWithKeys(fn (StoreConnection $c): array => [$c->shop_id => $c->shop?->name ?? $c->site_url])
                        ->all())
                    ->required(),
            ])
            ->action(function (array $data): void {
                SyncCatalogJob::dispatch((string) $data['shop_id']);

                $notification = Notification::make()
                    ->title(__('catalog::catalog.notifications.sync_queued'))
                    ->body(__('catalog::catalog.notifications.sync_queued_body'))
                    ->success();

                if (Route::has(self::RUNS_ROUTE)) {
                    $notification->actions([
                        Action::make('view_runs')
                            ->label(__('catalog::catalog.notifications.view_runs'))
                            ->url(route(self::RUNS_ROUTE)),
                    ]);
                }

                $notification->send();
            });
    }
}
