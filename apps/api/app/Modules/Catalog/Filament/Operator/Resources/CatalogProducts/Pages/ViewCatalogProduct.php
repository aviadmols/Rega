<?php

namespace App\Modules\Catalog\Filament\Operator\Resources\CatalogProducts\Pages;

use App\Modules\Catalog\Filament\Operator\Resources\CatalogProducts\CatalogProductResource;
use App\Modules\Catalog\Models\CatalogProduct;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewCatalogProduct extends ViewRecord
{
    protected static string $resource = CatalogProductResource::class;

    public function getTitle(): string
    {
        /** @var CatalogProduct $product */
        $product = $this->getRecord();

        return $product->title;
    }

    protected function getHeaderActions(): array
    {
        /** @var CatalogProduct $product */
        $product = $this->getRecord();

        return [
            Action::make('open_in_store')
                ->label(__('catalog::catalog.actions.open_in_store'))
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url($product->url, shouldOpenInNewTab: true)
                ->visible(filled($product->url)),
        ];
    }
}
