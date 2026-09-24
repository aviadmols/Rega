<?php

namespace App\Modules\Leads\Console;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Leads\Actions\ComposeCallsToAction;
use App\Modules\Leads\Actions\LearnFromReaders;
use App\Modules\Leads\Actions\WriteCallsToAction;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * Write what each page offers a reader.
 *
 *   leads compose gueta-avigdor
 *   leads compose --scheduled
 */
final class LeadsCommand extends Command
{
    protected $signature = 'leads
        {step : compose, write or learn}
        {target? : shop slug or ID}
        {--scheduled : every shop that is collecting}';

    protected $description = 'Compose the calls to action a shop offers its readers.';

    public function handle(TenantContext $tenant): int
    {
        return $tenant->runUnscoped(fn (): int => match ($this->argument('step')) {
            'compose' => $this->compose(),
            'write' => $this->write(),
            'learn' => $this->each(fn (Shop $shop): Run => app(LearnFromReaders::class)->handle($shop->id)),
            default => $this->failWith('Unknown step.'),
        });
    }

    private function compose(): int
    {
        $shops = $this->option('scheduled')
            ? Shop::query()->where('status', ShopStatus::Active)->orderBy('slug')->get()
            : collect([$this->shop()])->filter();

        if ($shops->isEmpty()) {
            return $this->failWith('Shop not found.');
        }

        $failed = 0;

        foreach ($shops as $shop) {
            if ($this->option('scheduled') && ! Features::enabled('leads.enabled', $shop->id)) {
                $this->line("{$shop->slug}: off");

                continue;
            }

            $run = app(ComposeCallsToAction::class)->handle($shop->id);
            $this->line("{$shop->slug}: ".$run->summary());
            $failed += $run->status->value === 'succeeded' ? 0 : 1;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param callable(Shop): Run $step */
    private function each(callable $step): int
    {
        $shops = $this->option('scheduled')
            ? Shop::query()->where('status', ShopStatus::Active)->orderBy('slug')->get()
            : collect([$this->shop()])->filter();

        if ($shops->isEmpty()) {
            return $this->failWith('Shop not found.');
        }

        $failed = 0;

        foreach ($shops as $shop) {
            if ($this->option('scheduled') && ! Features::enabled('leads.enabled', $shop->id)) {
                continue;
            }

            $run = $step($shop);
            $this->line("{$shop->slug}: ".$run->summary());
            $failed += $run->status->value === 'succeeded' ? 0 : 1;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** The two models, on the pages the templates left with only the generic offer. */
    private function write(): int
    {
        $shops = $this->option('scheduled')
            ? Shop::query()->where('status', ShopStatus::Active)->orderBy('slug')->get()
            : collect([$this->shop()])->filter();

        if ($shops->isEmpty()) {
            return $this->failWith('Shop not found.');
        }

        $failed = 0;

        foreach ($shops as $shop) {
            if ($this->option('scheduled') && ! Features::enabled('leads.enabled', $shop->id)) {
                continue;
            }

            $limit = (int) Settings::get('leads.pages_written_per_night', $shop->id);
            $run = app(WriteCallsToAction::class)->handle($shop->id, $limit);
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
