<?php

namespace App\Modules\Knowledge\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Knowledge\Actions\TakeKnowledgeSnapshot;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * Write down what is known about a shop, or about every shop.
 *
 *   knowledge snapshot gueta-avigdor
 *   knowledge snapshot --all
 */
final class KnowledgeCommand extends Command
{
    protected $signature = 'knowledge
        {step : snapshot}
        {target? : shop slug or ID}
        {--all : every active shop}';

    protected $description = 'Record what the system knows about a shop.';

    public function handle(TenantContext $tenant): int
    {
        return $tenant->runUnscoped(fn (): int => match ($this->argument('step')) {
            'snapshot' => $this->snapshot(),
            default => $this->failWith('Unknown step.'),
        });
    }

    private function snapshot(): int
    {
        $shops = $this->option('all')
            ? Shop::query()->where('status', ShopStatus::Active)->orderBy('slug')->get()
            : collect([$this->shop()])->filter();

        if ($shops->isEmpty()) {
            return $this->failWith('Shop not found.');
        }

        $failed = 0;

        foreach ($shops as $shop) {
            $run = app(TakeKnowledgeSnapshot::class)->handle($shop);
            $this->line("{$shop->slug}: ".$run->summary());
            $failed += $run->status->value === 'succeeded' ? 0 : 1;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function shop(): ?Shop
    {
        $key = (string) $this->argument('target');

        return Shop::query()->where('slug', $key)->orWhere('id', $key)->first();
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
