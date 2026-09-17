<?php

namespace App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentVocabularies\Pages;

use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentVocabularies\EnrichmentVocabularyResource;
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

final class ListEnrichmentVocabularies extends ListRecords
{
    protected static string $resource = EnrichmentVocabularyResource::class;

    public function getSubheading(): ?string
    {
        return __('enrichment::ui.vocabularies.subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add_vocabulary')
                ->label(__('enrichment::ui.actions.add_vocabulary'))
                ->icon(Heroicon::OutlinedPlus)
                ->schema([
                    Select::make('shop_id')
                        ->label(__('enrichment::ui.fields.shop'))
                        ->options(fn (): array => Shop::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->required(),
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
                        ->options(collect(ImportVocabulary::TEMPLATES)->mapWithKeys(fn (string $t): array => [$t => __("enrichment::ui.templates.{$t}")])->all())
                        ->required(fn (Get $get): bool => $get('source') === 'template')
                        ->visible(fn (Get $get): bool => $get('source') === 'template'),
                    FileUpload::make('file')
                        ->label(__('enrichment::ui.fields.vocabulary_file'))
                        ->disk('local')
                        ->directory('enrichment-uploads')
                        ->visibility('private')
                        ->maxSize(2048)
                        ->required(fn (Get $get): bool => $get('source') === 'file')
                        ->visible(fn (Get $get): bool => $get('source') === 'file'),
                    TextInput::make('author')
                        ->label(__('enrichment::ui.fields.author'))
                        ->helperText(__('enrichment::ui.fields.author_help'))
                        ->maxLength(120),
                ])
                ->action(function (array $data): void {
                    if (($data['source'] ?? 'template') === 'file') {
                        $path = (string) $data['file'];
                        $decoded = json_decode((string) Storage::disk('local')->get($path), true);
                        Storage::disk('local')->delete($path);
                    } else {
                        $decoded = ImportVocabulary::template((string) $data['template']);
                    }

                    if (! is_array($decoded)) {
                        Notification::make()->title(__('enrichment::ui.vocabularies.not_json'))->danger()->send();

                        return;
                    }

                    $run = app(ImportVocabulary::class)->handle((string) $data['shop_id'], $decoded, $data['author'] ?? null)['run'];

                    Notification::make()
                        ->title((string) $run->summary())
                        ->body($run->error)
                        ->{$run->status === RunStatus::Succeeded ? 'success' : 'danger'}()
                        ->send();
                }),
        ];
    }
}
