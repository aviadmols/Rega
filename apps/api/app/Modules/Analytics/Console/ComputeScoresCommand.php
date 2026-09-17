<?php

namespace App\Modules\Analytics\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Actions\ComputeScores;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * analytics:scores [shop]  — scores for one shop, or every connected shop (what the scheduler runs).
 */
final class ComputeScoresCommand extends Command
{
    protected $signature = 'analytics:scores {shop? : shop slug or ID; every connected shop when left out}';

    protected $description = 'Score widget sections from the last weeks of events and orders.';

    public function handle(TenantContext $tenant, ComputeScores $scores): int
    {
        $target = $this->argument('shop');

        $shopIds = $tenant->runUnscoped(fn () => $target !== null
            ? Shop::query()->where('slug', $target)->orWhere('id', $target)->pluck('id')
            : StoreConnection::query()->distinct()->pluck('shop_id'));

        if ($shopIds->isEmpty()) {
            $this->error('No shop to score.');

            return self::FAILURE;
        }

        foreach ($shopIds as $shopId) {
            $this->line((string) $scores->handle((string) $shopId)->summary());
        }

        return self::SUCCESS;
    }
}
