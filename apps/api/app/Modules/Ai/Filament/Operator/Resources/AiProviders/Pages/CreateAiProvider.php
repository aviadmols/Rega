<?php

namespace App\Modules\Ai\Filament\Operator\Resources\AiProviders\Pages;

use App\Modules\Ai\Filament\Operator\Resources\AiProviders\AiProviderResource;
use App\Modules\Ai\Models\AiProvider;
use App\Modules\Ai\Support\ProviderTestNotifier;
use Filament\Resources\Pages\CreateRecord;

final class CreateAiProvider extends CreateRecord
{
    protected static string $resource = AiProviderResource::class;

    /** A new key is checked right away, so a typo shows up before anything depends on it. */
    protected function afterCreate(): void
    {
        /** @var AiProvider $provider */
        $provider = $this->getRecord();

        ProviderTestNotifier::test($provider);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
