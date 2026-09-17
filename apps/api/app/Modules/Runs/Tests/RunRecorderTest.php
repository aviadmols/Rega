<?php

namespace App\Modules\Runs\Tests;

use App\Core\Facades\Settings;
use App\Modules\Admin\Models\User;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class RunRecorderTest extends TestCase
{
    use RefreshDatabase;

    private RecordsRuns $runs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runs = app(RecordsRuns::class);
    }

    public function test_a_successful_run_records_who_what_result_and_duration(): void
    {
        $shop = Shop::factory()->create();
        $user = User::factory()->operator()->create();
        $this->actingAs($user);

        $run = $this->runs->track(
            agent: 'connections.store_checker',
            action: 'connections.test',
            shopId: $shop->id,
            input: ['site_url' => 'https://store.test'],
            work: function (RunContext $run): void {
                $run->output(['products' => 12])->summary('runs::runs.summaries.done');
            },
        );

        $run->refresh();
        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame(RunTrigger::Manual, $run->trigger);
        $this->assertSame($shop->id, $run->shop_id);
        $this->assertSame($user->id, $run->user_id);
        $this->assertSame(['site_url' => 'https://store.test'], $run->input);
        $this->assertSame(['products' => 12], $run->output);
        $this->assertNotNull($run->finished_at);
        $this->assertIsInt($run->duration_ms);
        $this->assertSame(__('runs::runs.summaries.done'), $run->summary());
    }

    public function test_an_expected_failure_is_recorded_without_throwing(): void
    {
        $run = $this->runs->track('ai.provider_checker', 'ai.test_provider', function (RunContext $run): void {
            $run->fail('ai::runs.failures.invalid_key', ['provider' => 'OpenAI'], 'HTTP 401');
        });

        $this->assertSame(RunStatus::Failed, $run->fresh()->status);
        $this->assertSame('HTTP 401', $run->fresh()->error);
        $this->assertStringContainsString('OpenAI', (string) $run->fresh()->summary());
    }

    public function test_an_exception_fails_the_run_and_is_not_rethrown(): void
    {
        $run = $this->runs->track('connections.store_checker', 'connections.test', function (): void {
            throw new RuntimeException('database went away');
        });

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('runs::runs.summaries.unexpected_error', $run->summary_key);
        $this->assertStringContainsString('RuntimeException: database went away', (string) $run->error);
        $this->assertNotNull($run->finished_at);
    }

    public function test_credentials_never_reach_the_log_at_any_depth(): void
    {
        $run = $this->runs->track(
            agent: 'connections.store_checker',
            action: 'connections.test',
            input: ['site_url' => 'https://store.test', 'access_token' => 'rgt_secret', 'headers' => ['Authorization' => 'Bearer x']],
            work: function (RunContext $run): void {
                $run->output(['api_key' => 'sk-secret', 'nested' => ['password' => 'p', 'kept' => 'visible']]);
            },
        );

        $run->refresh();
        $this->assertSame('[redacted]', $run->input['access_token']);
        $this->assertSame('[redacted]', $run->input['headers']['Authorization']);
        $this->assertSame('[redacted]', $run->output['api_key']);
        $this->assertSame('[redacted]', $run->output['nested']['password']);
        $this->assertSame('visible', $run->output['nested']['kept']);
    }

    public function test_model_usage_adds_up_across_calls(): void
    {
        $run = $this->runs->track('factory.writer', 'factory.write', function (RunContext $run): void {
            $run->usage('openai', 'gpt-x', inputTokens: 1000, outputTokens: 200, costUsd: 0.01);
            $run->usage('openai', 'gpt-x', inputTokens: 500, outputTokens: 100, cacheReadTokens: 300, costUsd: 0.005);
        });

        $run->refresh();
        $this->assertSame(1500, $run->input_tokens);
        $this->assertSame(300, $run->output_tokens);
        $this->assertSame(300, $run->cache_read_tokens);
        $this->assertEqualsWithDelta(0.015, (float) $run->cost_usd, 0.000001);
    }

    public function test_labels_come_from_the_owning_module_and_fall_back_to_the_identifier(): void
    {
        $this->assertSame(__('connections::agents.store_checker'), Run::labelFor('agents', 'connections.store_checker'));
        $this->assertSame('mystery.agent', Run::labelFor('agents', 'mystery.agent'));
        $this->assertSame('nodot', Run::labelFor('agents', 'nodot'));
    }

    public function test_old_runs_are_pruned_after_the_retention_setting(): void
    {
        Settings::set('runs.retention_days', 30);

        $old = $this->runs->track('a.b', 'a.c', fn () => null);
        $old->forceFill(['created_at' => now()->subDays(31)])->save();
        $recent = $this->runs->track('a.b', 'a.c', fn () => null);

        $this->artisan('model:prune', ['--model' => [Run::class]])->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }
}
