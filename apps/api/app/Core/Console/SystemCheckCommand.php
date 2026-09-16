<?php

namespace App\Core\Console;

use App\Core\Modules\ModuleRepository;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/**
 * Checks that a running environment is wired correctly: database, migrations, pgvector,
 * cache, Redis, and the settings production must have. The container runs it on every web
 * boot, so a misconfigured deploy says what is wrong in the first lines of its log.
 *
 * Reports names and states only, never secrets.
 */
final class SystemCheckCommand extends Command
{
    protected $signature = 'system:check {--json : Print the results as JSON}';

    protected $description = 'Check database, migrations, pgvector, cache, Redis and production settings';

    /** @var list<array{check: string, status: 'ok'|'warn'|'fail'|'skip', detail: string}> */
    private array $results = [];

    public function handle(Migrator $migrator, ModuleRepository $modules): int
    {
        $this->check('app key', fn () => filled(config('app.key')) ? ['ok', 'set'] : ['fail', 'APP_KEY is empty']);

        $this->check('production settings', function () {
            if (! app()->isProduction()) {
                return ['skip', 'environment is '.app()->environment()];
            }

            $problems = array_filter([
                config('app.debug') ? 'APP_DEBUG is on' : null,
                ! str_starts_with((string) config('app.url'), 'https://') ? 'APP_URL is not https' : null,
            ]);

            return $problems === [] ? ['ok', 'debug off, https url'] : ['fail', implode('; ', $problems)];
        });

        $this->check('modules', fn () => ['ok', implode(', ', array_keys($modules->enabled()))]);

        $databaseUp = $this->check('database', function () {
            DB::connection()->getPdo();

            return ['ok', DB::connection()->getDriverName()];
        });

        $this->check('migrations', function () use ($migrator, $databaseUp) {
            if (! $databaseUp) {
                return ['skip', 'database unavailable'];
            }

            if (! $migrator->repositoryExists()) {
                return ['fail', 'migrations table missing'];
            }

            $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);
            $pending = array_diff(array_keys($files), $migrator->getRepository()->getRan());

            return $pending === [] ? ['ok', count($files).' ran'] : ['fail', count($pending).' pending'];
        });

        $this->check('pgvector', function () use ($databaseUp) {
            if (! $databaseUp || DB::connection()->getDriverName() !== 'pgsql') {
                return ['skip', 'not Postgres'];
            }

            $available = (bool) DB::scalar("select exists(select 1 from pg_available_extensions where name = 'vector')");

            return $available ? ['ok', 'available'] : ['fail', 'the vector extension is not installed on this server'];
        });

        $this->check('cache', function () {
            $key = 'system-check:'.Str::random(12);
            Cache::put($key, 'ok', 10);
            $value = Cache::get($key);
            Cache::forget($key);

            return $value === 'ok' ? ['ok', (string) config('cache.default')] : ['fail', 'value did not round-trip'];
        });

        $this->check('redis', function () {
            $users = array_keys(array_filter([
                'cache' => config('cache.default') === 'redis',
                'queue' => config('queue.default') === 'redis',
                'session' => config('session.driver') === 'redis',
            ]));

            if ($users === []) {
                return ['skip', 'not used'];
            }

            Redis::connection()->ping();

            return ['ok', 'used by '.implode(', ', $users)];
        });

        return $this->report();
    }

    /**
     * @param  callable(): array{0: 'ok'|'warn'|'fail'|'skip', 1: string}  $probe
     */
    private function check(string $name, callable $probe): bool
    {
        try {
            [$status, $detail] = $probe();
        } catch (Throwable $e) {
            [$status, $detail] = ['fail', class_basename($e).': '.Str::limit($e->getMessage(), 160)];
        }

        $this->results[] = ['check' => $name, 'status' => $status, 'detail' => $detail];

        return $status === 'ok';
    }

    private function report(): int
    {
        $failed = array_filter($this->results, fn (array $r) => $r['status'] === 'fail');

        if ($this->option('json')) {
            $this->line(json_encode(['ok' => $failed === [], 'checks' => $this->results], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($this->results as $result) {
                $line = str_pad($result['check'], 20, '.').' '.$result['detail'];

                match ($result['status']) {
                    'ok' => $this->components->info($line),
                    'fail' => $this->components->error($line),
                    default => $this->components->warn(strtoupper($result['status']).' '.$line),
                };
            }
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
