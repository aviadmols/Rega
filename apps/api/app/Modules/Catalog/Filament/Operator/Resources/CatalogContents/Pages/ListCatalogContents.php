<?php

namespace App\Modules\Catalog\Filament\Operator\Resources\CatalogContents\Pages;

use App\Modules\Catalog\Filament\Operator\Resources\CatalogContents\CatalogContentResource;
use App\Modules\Catalog\Support\SyncCatalogAction;
use Filament\Resources\Pages\ListRecords;

final class ListCatalogContents extends ListRecords
{
    protected static string $resource = CatalogContentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SyncCatalogAction::make(),
        ];
    }
}
