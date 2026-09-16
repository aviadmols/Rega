<?php

namespace App\Modules\Tenancy\Filament\Operator\Resources\Shops\Pages;

use App\Modules\Tenancy\Actions\CreateShop;
use App\Modules\Tenancy\Filament\Operator\Resources\Shops\ShopResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditShop extends EditRecord
{
    protected static string $resource = ShopResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['domain'] = CreateShop::normalizeDomain($data['domain']);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
