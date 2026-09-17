<?php

namespace App\Modules\Ai\Filament\Operator\Resources\AiProviders\Pages;

use App\Modules\Ai\Filament\Operator\Resources\AiProviders\AiProviderResource;
use App\Modules\Ai\Models\AiProvider;
use App\Modules\Ai\Support\ProviderTestNotifier;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

final class EditAiProvider extends EditRecord
{
    protected static string $resource = AiProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label(__('ai::providers.actions.test'))
                ->icon(Heroicon::OutlinedSignal)
                ->action(fn () => $this->testAndRefresh()),
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        if ($this->getRecord()->wasChanged('api_key')) {
            $this->testAndRefresh();
        }
    }

    private function testAndRefresh(): void
    {
        /** @var AiProvider $provider */
        $provider = $this->getRecord();

        ProviderTestNotifier::test($provider);

        $this->redirect($this->getResource()::getUrl('edit', ['record' => $provider]), navigate: false);
    }
}
