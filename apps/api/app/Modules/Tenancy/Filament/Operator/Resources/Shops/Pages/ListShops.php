<?php

namespace App\Modules\Tenancy\Filament\Operator\Resources\Shops\Pages;

use App\Modules\Tenancy\Filament\Operator\Resources\Shops\ShopResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListShops extends ListRecords
{
    protected static string $resource = ShopResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
