<?php

namespace App\Modules\Enrichment\Support;

use App\Modules\Enrichment\Actions\CreateTaskFile;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Tenancy\Models\Shop;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/** "Create task file": code builds the requests; the operator downloads and runs them. */
final class CreateTaskFileAction
{
    public static function make(): Action
    {
        return Action::make('create_task_file')
            ->label(__('enrichment::ui.actions.create'))
            ->icon(Heroicon::OutlinedPlus)
            ->modalDescription(__('enrichment::ui.actions.create_help'))
            ->schema([
                Select::make('shop_id')
                    ->label(__('enrichment::ui.fields.shop'))
                    ->options(fn (): array => Shop::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->live(),
                Select::make('task')
                    ->label(__('enrichment::ui.fields.task'))
                    ->options(TaskType::options())
                    ->required()
                    ->live(),
                Select::make('subject')
                    ->label(__('enrichment::ui.fields.subject_type'))
                    ->options(['product' => __('enrichment::ui.subjects.product'), 'content' => __('enrichment::ui.subjects.content')])
                    ->default('product')
                    ->live()
                    ->visible(fn (Get $get): bool => $get('task') === TaskType::FactReview->value),
                Select::make('review_tier')
                    ->label(__('enrichment::ui.fields.tier'))
                    ->options([1 => __('enrichment::ui.tiers.1'), 2 => __('enrichment::ui.tiers.2')])
                    ->default(1)
                    ->visible(fn (Get $get): bool => $get('task') === TaskType::FactReview->value),
                Select::make('vocabulary_id')
                    ->label(__('enrichment::ui.fields.vocabulary'))
                    ->options(fn (Get $get): array => EnrichmentVocabulary::query()
                        ->where('shop_id', $get('shop_id'))
                        ->where('active', true)
                        ->get()
                        ->mapWithKeys(fn (EnrichmentVocabulary $v): array => [$v->id => $v->title()])
                        ->all())
                    ->required(fn (Get $get): bool => self::needsVocabulary($get))
                    ->visible(fn (Get $get): bool => self::needsVocabulary($get)),
                TextInput::make('limit')
                    ->label(__('enrichment::ui.fields.limit'))
                    ->helperText(__('enrichment::ui.fields.limit_help'))
                    ->numeric()
                    ->minValue(1),
            ])
            ->action(function (array $data): void {
                $type = TaskType::from((string) $data['task']);

                $result = app(CreateTaskFile::class)->handle(
                    shopId: (string) $data['shop_id'],
                    type: $type,
                    vocabularyId: $data['vocabulary_id'] ?? null,
                    reviewTier: (int) ($data['review_tier'] ?? 1),
                    scope: $type === TaskType::FactReview ? ['subject' => $data['subject'] ?? 'product'] : [],
                    limit: filled($data['limit'] ?? null) ? (int) $data['limit'] : null,
                );

                $batch = $result['batch'];
                $notification = Notification::make()->title((string) $result['run']->summary());

                if ($batch === null) {
                    $notification->warning();
                } else {
                    $notification->success()->persistent()->actions([
                        Action::make('download')
                            ->label(__('enrichment::ui.actions.download'))
                            ->url(route('enrichment.batches.download', ['batch' => $batch->id])),
                    ]);
                }

                $notification->send();
            });
    }

    private static function needsVocabulary(Get $get): bool
    {
        return $get('task') === TaskType::ProductExtraction->value
            || ($get('task') === TaskType::FactReview->value && ($get('subject') ?? 'product') === 'product');
    }
}
