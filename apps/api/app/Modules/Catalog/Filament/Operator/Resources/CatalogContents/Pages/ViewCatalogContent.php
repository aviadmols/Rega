<?php

namespace App\Modules\Catalog\Filament\Operator\Resources\CatalogContents\Pages;

use App\Modules\Catalog\Filament\Operator\Resources\CatalogContents\CatalogContentResource;
use App\Modules\Catalog\Models\CatalogContent;
use Filament\Resources\Pages\ViewRecord;

final class ViewCatalogContent extends ViewRecord
{
    protected static string $resource = CatalogContentResource::class;

    public function getTitle(): string
    {
        /** @var CatalogContent $content */
        $content = $this->getRecord();

        return $content->title;
    }
}
