<?php

namespace App\Modules\Ai\Actions;

use App\Modules\Ai\Contracts\ListsProviderModels;
use App\Modules\Ai\Enums\ProviderStatus;
use App\Modules\Ai\Models\AiProvider;
use App\Modules\Ai\Support\ProviderCheckFailed;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;

/**
 * Checks a provider key by listing its models (free), saves the list, and records the check in
 * the activity log.
 */
final class TestAiProvider
{
    public const AGENT = 'ai.provider_checker';

    public const ACTION = 'ai.test_provider';

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly ListsProviderModels $models,
    ) {}

    public function handle(AiProvider $provider): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            input: ['provider' => $provider->provider->value, 'key_hint' => $provider->key_hint],
            work: fn (RunContext $run) => $this->check($provider, $run),
        );
    }

    private function check(AiProvider $provider, RunContext $run): void
    {
        try {
            $models = $this->models->list($provider->provider, $provider->api_key);
        } catch (ProviderCheckFailed $e) {
            $provider->forceFill([
                'status' => ProviderStatus::Failed,
                'last_error_code' => $e->reason,
                'last_checked_at' => now(),
            ])->saveQuietly();

            $run->output(['http_status' => $e->httpStatus])
                ->fail("ai::runs.failures.{$e->reason}", ['provider' => $provider->provider->label()], $e->getMessage());

            return;
        }

        $provider->forceFill([
            'status' => ProviderStatus::Connected,
            'last_error_code' => null,
            'last_checked_at' => now(),
            'models' => $models,
        ])->saveQuietly();

        $run->output([
            'model_count' => count($models),
            'newest_models' => array_slice(array_column($models, 'id'), 0, 10),
        ])->summary('ai::runs.connected', [
            'provider' => $provider->provider->label(),
            'count' => count($models),
        ]);
    }
}
