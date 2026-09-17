<?php

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\Enums\AiProviderName;

/**
 * One request to a model with the key saved in the panel, answered as a JSON object.
 *
 * Callers ask SpendGuard first and record the reply's tokens and cost on their run.
 */
interface ChatModel
{
    /** @throws ModelCallFailed when there is no connected key, the provider refuses, or the reply is not JSON */
    public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply;
}
