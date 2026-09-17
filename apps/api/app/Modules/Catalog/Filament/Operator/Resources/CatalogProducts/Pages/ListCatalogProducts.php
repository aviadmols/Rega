<?php

namespace App\Modules\Catalog\Filament\Operator\Resources\CatalogProducts\Pages;

use App\Modules\Catalog\Filament\Operator\Resources\CatalogProducts\CatalogProductResource;
use App\Modules\Catalog\Support\SyncCatalogAction;
use Filament\Resources\Pages\ListRecords;

final class ListCatalogProducts extends ListRecords
{
    protected static string $resource = CatalogProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SyncCatalogAction::make(),
        ];
    }
}
