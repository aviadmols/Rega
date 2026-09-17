<?php

namespace App\Modules\Runs\Filament\Operator\Resources\Runs\Pages;

use App\Modules\Runs\Filament\Operator\Resources\Runs\RunResource;
use Filament\Resources\Pages\ListRecords;

final class ListRuns extends ListRecords
{
    protected static string $resource = RunResource::class;
}
