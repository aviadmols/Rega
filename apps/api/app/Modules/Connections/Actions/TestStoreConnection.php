<?php

namespace App\Modules\Connections\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Calls the store plugin's /rega/v1/status with the saved token and records what happened,
 * both on the connection and as a run in the activity log.
 */
final class TestStoreConnection
{
    public const AGENT = 'connections.store_checker';

    public const ACTION = 'connections.test';

    private const TIMEOUT_SECONDS = 20;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(StoreConnection $connection): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $connection->shop_id,
            input: ['site_url' => $connection->site_url, 'token_prefix' => $connection->token_prefix],
            work: fn (RunContext $run) => $this->check($connection, $run),
        );
    }

    private function check(StoreConnection $connection, RunContext $run): void
    {
        try {
            [$response, $url] = $this->request($connection);
        } catch (ConnectionException $e) {
            $this->fail($connection, $run, 'unreachable', [], $e->getMessage());

            return;
        }

        $run->output(['url' => $url, 'http_status' => $response->status()]);
        $code = (string) $response->json('code', '');

        $failure = match (true) {
            $response->status() === 401 => 'invalid_token',
            $response->status() === 429 => 'locked_out',
            $response->status() === 404 && ! str_starts_with($code, 'rega_') => 'plugin_missing',
            $response->status() === 503 && $code === 'rega_woocommerce_inactive' => 'woocommerce_inactive',
            ! $response->successful() => 'http_error',
            ! is_array($response->json('data.plugin')) => 'unexpected_response',
            default => null,
        };

        if ($failure !== null) {
            $this->fail($connection, $run, $failure, ['status' => $response->status()], $code !== '' ? $code : null);

            return;
        }

        $info = (array) $response->json('data');

        $this->save($connection, [
            'status' => ConnectionStatus::Connected,
            'last_error_code' => null,
            'site_info' => $info,
        ]);

        $products = (int) data_get($info, 'counts.products.publish', 0);

        $run->output([
            'plugin_version' => data_get($info, 'plugin.version'),
            'wordpress' => data_get($info, 'site.wordpress'),
            'woocommerce' => data_get($info, 'woocommerce.version'),
            'published_products' => $products,
        ])->summary('connections::runs.connected', [
            'products' => number_format($products),
            'version' => (string) data_get($info, 'plugin.version', '?'),
        ]);
    }

    /**
     * Pretty permalinks first; sites without them only answer on ?rest_route=.
     *
     * @return array{0: Response, 1: string}
     */
    private function request(StoreConnection $connection): array
    {
        $client = Http::acceptJson()
            ->withHeaders(['X-Rega-Token' => $connection->access_token, 'User-Agent' => 'Rega/'.config('app.name')])
            ->connectTimeout(10)
            ->timeout(self::TIMEOUT_SECONDS);

        $url = $connection->site_url.'/wp-json/rega/v1/status';
        $response = $client->get($url);

        if ($response->status() === 404 && ! str_starts_with((string) $response->json('code', ''), 'rega_')) {
            $url = $connection->site_url.'/?rest_route=/rega/v1/status';
            $response = $client->get($url);
        }

        return [$response, $url];
    }

    /** @param array<string, scalar> $params */
    private function fail(StoreConnection $connection, RunContext $run, string $code, array $params = [], ?string $detail = null): void
    {
        $this->save($connection, [
            'status' => ConnectionStatus::Failed,
            'last_error_code' => $code,
        ]);

        $run->fail("connections::runs.failures.{$code}", $params, $detail);
    }

    /** @param array<string, mixed> $attributes */
    private function save(StoreConnection $connection, array $attributes): void
    {
        $this->tenant->run($connection->shop_id, function () use ($connection, $attributes): void {
            $connection->forceFill($attributes + ['last_checked_at' => now()])->saveQuietly();
        });
    }
}
