<?php

namespace App\Modules\Connections\Filament\Operator\Resources\StoreConnections\Pages;

use App\Modules\Connections\Filament\Operator\Resources\StoreConnections\StoreConnectionResource;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\ConnectionTestNotifier;
use Filament\Resources\Pages\CreateRecord;

final class CreateStoreConnection extends CreateRecord
{
    protected static string $resource = StoreConnectionResource::class;

    /** A new connection is tested right away, so the operator sees at once whether the token works. */
    protected function afterCreate(): void
    {
        /** @var StoreConnection $connection */
        $connection = $this->getRecord();

        ConnectionTestNotifier::test($connection);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
