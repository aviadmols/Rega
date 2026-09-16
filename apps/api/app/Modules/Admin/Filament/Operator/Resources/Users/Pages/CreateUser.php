<?php

namespace App\Modules\Admin\Filament\Operator\Resources\Users\Pages;

use App\Modules\Admin\Filament\Operator\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;
}
