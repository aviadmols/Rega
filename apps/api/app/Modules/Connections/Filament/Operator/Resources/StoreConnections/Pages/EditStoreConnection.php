<?php

namespace App\Modules\Connections\Filament\Operator\Resources\StoreConnections\Pages;

use App\Modules\Connections\Filament\Operator\Resources\StoreConnections\StoreConnectionResource;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\ConnectionTestNotifier;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

final class EditStoreConnection extends EditRecord
{
    protected static string $resource = StoreConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label(__('connections::connections.actions.test'))
                ->icon(Heroicon::OutlinedSignal)
                ->action(function (): void {
                    $this->testAndRefresh();
                }),
            DeleteAction::make(),
        ];
    }

    /** Changing the address or the token invalidates the last result, so test again. */
    protected function afterSave(): void
    {
        if ($this->getRecord()->wasChanged(['site_url', 'access_token'])) {
            $this->testAndRefresh();
        }
    }

    private function testAndRefresh(): void
    {
        /** @var StoreConnection $connection */
        $connection = $this->getRecord();

        ConnectionTestNotifier::test($connection);

        $this->redirect($this->getResource()::getUrl('edit', ['record' => $connection]), navigate: false);
    }
}
