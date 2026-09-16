<?php

namespace App\Modules\Tenancy\Filament\Operator\Resources\Shops\Pages;

use App\Modules\Tenancy\Actions\CreateShop as CreateShopAction;
use App\Modules\Tenancy\Filament\Operator\Resources\Shops\ShopResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateShop extends CreateRecord
{
    protected static string $resource = ShopResource::class;

    /** Creation goes through the action, so the panel and code create shops the same way. */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateShopAction::class)->handle($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
