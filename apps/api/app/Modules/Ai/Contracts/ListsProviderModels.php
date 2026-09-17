<?php

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Ai\Support\ProviderCheckFailed;

/**
 * Lists the models an API key can use. Listing models is free on both providers, so it is how a
 * key is checked without spending tokens.
 */
interface ListsProviderModels
{
    /**
     * @return list<array{id: string, name: string, created_at: ?string}> newest first
     *
     * @throws ProviderCheckFailed
     */
    public function list(AiProviderName $provider, string $apiKey): array;
}
