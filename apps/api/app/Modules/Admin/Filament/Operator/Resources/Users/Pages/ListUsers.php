<?php

namespace App\Modules\Admin\Filament\Operator\Resources\Users\Pages;

use App\Modules\Admin\Filament\Operator\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
