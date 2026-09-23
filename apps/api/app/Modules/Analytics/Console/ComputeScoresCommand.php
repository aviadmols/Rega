<?php

namespace App\Modules\Analytics\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Actions\ComputePopularity;
use App\Modules\Analytics\Actions\ComputePriors;
use App\Modules\Analytics\Actions\ComputeScores;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * analytics:scores [shop]  — scores and product popularity for one shop, or every connected
 * shop (what the scheduler runs).
 */
final class ComputeScoresCommand extends Command
{
    protected $signature = 'analytics:scores {shop? : shop slug or ID; every connected shop when left out}';

    protected $description = 'Score widget sections and count how wanted each product is, from the last weeks of events and orders.';

    public function handle(TenantContext $tenant, ComputeScores $scores, ComputePopularity $popularity): int
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
            $this->line((string) $popularity->handle((string) $shopId)->summary());
        }

        // Once every shop has been scored, what a trade as a whole has learned — the starting
        // point a shop with no scores of its own is given instead of the order of a loop.
        if ($target === null) {
            $this->line((string) app(ComputePriors::class)->handle()->summary());
        }

        return self::SUCCESS;
    }
}
