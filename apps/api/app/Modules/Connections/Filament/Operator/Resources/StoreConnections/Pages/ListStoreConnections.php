<?php

namespace App\Modules\Connections\Filament\Operator\Resources\StoreConnections\Pages;

use App\Modules\Connections\Filament\Operator\Resources\StoreConnections\StoreConnectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListStoreConnections extends ListRecords
{
    protected static string $resource = StoreConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
