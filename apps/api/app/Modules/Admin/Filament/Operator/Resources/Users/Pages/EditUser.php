<?php

namespace App\Modules\Admin\Filament\Operator\Resources\Users\Pages;

use App\Modules\Admin\Filament\Operator\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
