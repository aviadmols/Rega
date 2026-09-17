<?php

namespace App\Modules\Catalog\Filament\Operator\Resources\CatalogProducts\Schemas;

use App\Modules\Catalog\Models\CatalogProduct;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

final class CatalogProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('catalog::catalog.sections.product'))
                    ->columns(4)
                    ->schema([
                        ImageEntry::make('image_url')
                            ->label(__('catalog::catalog.fields.image'))
                            ->imageHeight(120)
                            ->placeholder('-'),
                        TextEntry::make('shop.name')->label(__('catalog::catalog.fields.shop')),
                        TextEntry::make('external_id')
                            ->label(__('catalog::catalog.fields.external_id'))
                            ->fontFamily(FontFamily::Mono),
                        TextEntry::make('sku')->label(__('catalog::catalog.fields.sku'))->placeholder('-'),
                        TextEntry::make('brand')->label(__('catalog::catalog.fields.brand'))->placeholder('-'),
                        TextEntry::make('price')
                            ->label(__('catalog::catalog.fields.price'))
                            ->money(fn (CatalogProduct $record): string => $record->currency ?? 'ILS')
                            ->placeholder('-'),
                        TextEntry::make('in_stock')
                            ->label(__('catalog::catalog.fields.in_stock'))
                            ->formatStateUsing(fn (bool $state): string => $state ? __('catalog::catalog.values.yes') : __('catalog::catalog.values.no')),
                        TextEntry::make('variations_count')->label(__('catalog::catalog.fields.variations')),
                        TextEntry::make('categories')
                            ->label(__('catalog::catalog.fields.categories'))
                            ->state(fn (CatalogProduct $record): array => array_map(fn (array $path): string => implode(' › ', $path), $record->categoryPaths()))
                            ->listWithLineBreaks()
                            ->columnSpan(2),
                        TextEntry::make('source_updated_at')->label(__('catalog::catalog.fields.updated_in_store'))->dateTime()->placeholder('-'),
                        TextEntry::make('synced_at')->label(__('catalog::catalog.fields.synced_at'))->dateTime()->placeholder('-'),
                    ]),
                Section::make(__('catalog::catalog.sections.text'))
                    ->schema([
                        TextEntry::make('short_description')
                            ->label(__('catalog::catalog.fields.short_description'))
                            ->state(fn (CatalogProduct $record): string => $record->shortDescription())
                            ->placeholder('-')
                            ->extraAttributes(['style' => 'white-space: pre-line']),
                        TextEntry::make('description')
                            ->label(__('catalog::catalog.fields.description'))
                            ->state(fn (CatalogProduct $record): string => $record->description())
                            ->placeholder('-')
                            ->extraAttributes(['style' => 'white-space: pre-line']),
                    ]),
                Section::make(__('catalog::catalog.sections.specs'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('store_attributes')
                            ->label(__('catalog::catalog.fields.store_attributes'))
                            ->state(fn (CatalogProduct $record): array => array_map(
                                fn (array $a): string => $a['name'].': '.implode(', ', $a['values']),
                                $record->storeAttributes(),
                            ))
                            ->listWithLineBreaks()
                            ->placeholder('-'),
                        TextEntry::make('spec_fields')
                            ->label(__('catalog::catalog.fields.spec_fields'))
                            ->state(fn (CatalogProduct $record): array => $record->specFields())
                            ->listWithLineBreaks()
                            ->placeholder('-'),
                        TextEntry::make('relations')
                            ->label(__('catalog::catalog.fields.relations'))
                            ->state(fn (CatalogProduct $record): array => array_map(
                                fn (array $r): string => __("catalog::catalog.relations.{$r['type']}").' #'.$r['target'],
                                $record->merchantRelations(),
                            ))
                            ->listWithLineBreaks()
                            ->placeholder('-'),
                    ]),
            ]);
    }
}
