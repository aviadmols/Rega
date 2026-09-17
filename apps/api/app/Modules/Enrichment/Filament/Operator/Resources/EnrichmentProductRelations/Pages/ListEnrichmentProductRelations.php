<?php

namespace App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentProductRelations\Pages;

use App\Modules\Enrichment\Actions\ComputeProductRelations;
use App\Modules\Enrichment\Actions\ImportRelationRules;
use App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentProductRelations\EnrichmentProductRelationResource;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Tenancy\Models\Shop;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

final class ListEnrichmentProductRelations extends ListRecords
{
    protected static string $resource = EnrichmentProductRelationResource::class;

    public function getSubheading(): ?string
    {
        return __('enrichment::ui.relations.subheading');
    }

    protected function getHeaderActions(): array
    {
        $shop = fn (): Select => Select::make('shop_id')
            ->label(__('enrichment::ui.fields.shop'))
            ->options(fn (): array => Shop::query()->orderBy('name')->pluck('name', 'id')->all())
            ->required();

        return [
            Action::make('save_rules')
                ->label(__('enrichment::ui.actions.save_relation_rules'))
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->color('gray')
                ->schema([
                    $shop(),
                    Radio::make('source')
                        ->label(__('enrichment::ui.fields.source'))
                        ->options([
                            'template' => __('enrichment::ui.vocabularies.from_template'),
                            'file' => __('enrichment::ui.vocabularies.from_file'),
                        ])
                        ->default('template')
                        ->live()
                        ->required(),
                    Select::make('template')
                        ->label(__('enrichment::ui.fields.template'))
                        ->options(collect(ImportRelationRules::TEMPLATES)->mapWithKeys(fn (string $t): array => [$t => __("enrichment::ui.templates.{$t}")])->all())
                        ->required(fn (Get $get): bool => $get('source') === 'template')
                        ->visible(fn (Get $get): bool => $get('source') === 'template'),
                    FileUpload::make('file')
                        ->label(__('enrichment::ui.relations.rules_file'))
                        ->disk('local')
                        ->directory('enrichment-uploads')
                        ->visibility('private')
                        ->maxSize(1024)
                        ->required(fn (Get $get): bool => $get('source') === 'file')
                        ->visible(fn (Get $get): bool => $get('source') === 'file'),
                    TextInput::make('author')->label(__('enrichment::ui.fields.author'))->maxLength(120),
                ])
                ->action(function (array $data): void {
                    if (($data['source'] ?? 'template') === 'file') {
                        $path = (string) $data['file'];
                        $decoded = json_decode((string) Storage::disk('local')->get($path), true);
                        Storage::disk('local')->delete($path);
                    } else {
                        $decoded = ImportRelationRules::template((string) $data['template']);
                    }

                    if (! is_array($decoded)) {
                        Notification::make()->title(__('enrichment::ui.vocabularies.not_json'))->danger()->send();

                        return;
                    }

                    $run = app(ImportRelationRules::class)->handle((string) $data['shop_id'], $decoded, $data['author'] ?? null)['run'];

                    Notification::make()->title((string) $run->summary())->body($run->error)
                        ->{$run->status === RunStatus::Succeeded ? 'success' : 'danger'}()->send();
                }),
            Action::make('compute')
                ->label(__('enrichment::ui.actions.compute_relations'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->schema([$shop()])
                ->action(function (array $data): void {
                    $run = app(ComputeProductRelations::class)->handle((string) $data['shop_id']);

                    Notification::make()->title((string) $run->summary())->body($run->error)
                        ->{$run->status === RunStatus::Succeeded ? 'success' : 'danger'}()->send();
                }),
        ];
    }
}
