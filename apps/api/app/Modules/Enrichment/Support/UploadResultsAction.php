<?php

namespace App\Modules\Enrichment\Support;

use App\Core\Facades\Settings;
use App\Modules\Enrichment\Actions\ImportTaskResults;
use App\Modules\Enrichment\Enums\BatchStatus;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Runs\Enums\RunStatus;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

/** "Upload answers": reads a model's answers into the batch through every check. */
final class UploadResultsAction
{
    private const DISK = 'local';

    public static function make(): Action
    {
        return Action::make('upload_results')
            ->label(__('enrichment::ui.actions.upload'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->visible(fn (EnrichmentBatch $record): bool => $record->status === BatchStatus::AwaitingResults)
            ->modalDescription(__('enrichment::ui.actions.upload_help'))
            ->schema(fn (EnrichmentBatch $record): array => [
                TextInput::make('model')
                    ->label(__('enrichment::ui.fields.model'))
                    ->helperText(__('enrichment::ui.fields.model_help'))
                    ->default($record->task->suggestedModel($record->review_tier))
                    ->required()
                    ->maxLength(120),
                FileUpload::make('file')
                    ->label(__('enrichment::ui.fields.results_file'))
                    ->disk(self::DISK)
                    ->directory('enrichment-uploads')
                    ->visibility('private')
                    ->maxSize((int) Settings::get('enrichment.max_upload_kilobytes'))
                    ->required(),
            ])
            ->action(function (EnrichmentBatch $record, array $data): void {
                $path = (string) $data['file'];
                $contents = (string) Storage::disk(self::DISK)->get($path);
                Storage::disk(self::DISK)->delete($path);

                $run = app(ImportTaskResults::class)->handle($record, $contents, (string) $data['model']);

                Notification::make()
                    ->title((string) $run->summary())
                    ->{$run->status === RunStatus::Succeeded ? 'success' : 'danger'}()
                    ->duration(10000)
                    ->send();
            });
    }
}
