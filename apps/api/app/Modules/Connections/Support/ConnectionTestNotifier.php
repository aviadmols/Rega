<?php

namespace App\Modules\Connections\Support;

use App\Modules\Connections\Actions\TestStoreConnection;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Runs\Enums\RunStatus;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * Runs a connection test from an admin button and tells the operator the result, with a link
 * to the run in the activity log.
 */
final class ConnectionTestNotifier
{
    /** The activity screen belongs to the Runs module; linking by route name avoids importing it. */
    private const RUN_ROUTE = 'filament.operator.resources.runs.view';

    public static function test(StoreConnection $connection): void
    {
        $run = app(TestStoreConnection::class)->handle($connection);

        $notification = Notification::make()
            ->title($run->status === RunStatus::Succeeded ? __('connections::connections.notifications.connected') : __('connections::connections.notifications.failed'))
            ->body($run->summary());

        $run->status === RunStatus::Succeeded ? $notification->success() : $notification->danger()->persistent();

        if (Route::has(self::RUN_ROUTE)) {
            $notification->actions([
                Action::make('view_run')
                    ->label(__('connections::connections.notifications.view_run'))
                    ->url(route(self::RUN_ROUTE, ['record' => $run->id])),
            ]);
        }

        $notification->send();
    }
}
