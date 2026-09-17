<?php

namespace App\Modules\Ai\Support;

use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Ai\Enums\ProviderStatus;
use App\Modules\Ai\Models\AiProvider;
use GuzzleHttp\Client;
use OpenAI;
use Throwable;

/**
 * Chat completions through openai-php/client with the key saved in the panel, asking for a JSON
 * object. Anthropic calls go through the Batch API elsewhere and are not offered here yet.
 */
final class SdkChatModel implements ChatModel
{
    private const TIMEOUT_SECONDS = 45.0;

    public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
    {
        if ($provider !== AiProviderName::OpenAi) {
            throw new ModelCallFailed(ModelCallFailed::UNSUPPORTED);
        }

        $key = AiProvider::query()->where('provider', $provider)->where('status', ProviderStatus::Connected)->first()?->api_key;

        if ($key === null || $key === '') {
            throw new ModelCallFailed(ModelCallFailed::NO_KEY);
        }

        try {
            $response = OpenAI::factory()
                ->withApiKey($key)
                ->withHttpClient(new Client(['timeout' => self::TIMEOUT_SECONDS, 'connect_timeout' => 10]))
                ->make()
                ->chat()
                ->create(array_filter([
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'max_completion_tokens' => $maxOutputTokens,
                    'reasoning_effort' => $reasoningEffort,
                ], fn ($value): bool => $value !== null && $value !== ''));
        } catch (Throwable $e) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, $e);
        }

        $data = json_decode((string) ($response->choices[0]->message->content ?? ''), true);

        if (! is_array($data)) {
            throw new ModelCallFailed(ModelCallFailed::NOT_JSON);
        }

        return new ModelReply($data, (int) ($response->usage?->promptTokens ?? 0), (int) ($response->usage?->completionTokens ?? 0));
    }
}
