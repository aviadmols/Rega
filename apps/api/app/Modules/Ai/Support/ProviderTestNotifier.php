<?php

namespace App\Modules\Ai\Support;

use App\Modules\Ai\Actions\TestAiProvider;
use App\Modules\Ai\Models\AiProvider;
use App\Modules\Runs\Enums\RunStatus;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Route;

final class ProviderTestNotifier
{
    private const RUN_ROUTE = 'filament.operator.resources.runs.view';

    public static function test(AiProvider $provider): void
    {
        $run = app(TestAiProvider::class)->handle($provider);
        $succeeded = $run->status === RunStatus::Succeeded;

        $notification = Notification::make()
            ->title($succeeded ? __('ai::providers.notifications.connected') : __('ai::providers.notifications.failed'))
            ->body($run->summary());

        $succeeded ? $notification->success() : $notification->danger()->persistent();

        if (Route::has(self::RUN_ROUTE)) {
            $notification->actions([
                Action::make('view_run')
                    ->label(__('ai::providers.notifications.view_run'))
                    ->url(route(self::RUN_ROUTE, ['record' => $run->id])),
            ]);
        }

        $notification->send();
    }
}
