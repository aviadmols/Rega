<?php

namespace App\Modules\Ai\Filament\Operator\Resources\AiProviders\Pages;

use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Ai\Filament\Operator\Resources\AiProviders\AiProviderResource;
use App\Modules\Ai\Models\AiProvider;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListAiProviders extends ListRecords
{
    protected static string $resource = AiProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // One key per provider: nothing to add once every provider has one.
            CreateAction::make()->visible(fn (): bool => AiProvider::query()->count() < count(AiProviderName::cases())),
        ];
    }
}
