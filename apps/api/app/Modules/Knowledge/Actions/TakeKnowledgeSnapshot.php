<?php

namespace App\Modules\Knowledge\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Knowledge\Models\KnowledgeSnapshot;
use App\Modules\Knowledge\Support\Census;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;

/**
 * Everything known about a shop today, written down so tomorrow can be compared to it.
 *
 * The rest of the system holds only the present tense: these are the facts, this is the order the
 * panels are in. Nothing kept yesterday, so nothing could answer "what did the system learn this
 * week". A row a night per shop can, and the difference between two of them is that answer.
 *
 * The counting itself lives in the census, because the screen reads the same numbers live and the
 * two must never disagree.
 */
final class TakeKnowledgeSnapshot
{
    public const AGENT = 'knowledge.recorder';

    public const ACTION = 'knowledge.snapshot';

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly InferVertical $vertical,
    ) {}

    public function handle(Shop $shop): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shop->id,
            work: function (RunContext $run) use ($shop): void {
                $this->vertical->handle($shop);

                $snapshot = $this->tenant->run($shop->id, fn (): KnowledgeSnapshot => $this->write($shop));

                $run->output([
                    'vertical' => $shop->fresh()?->vertical,
                    'coverage' => $snapshot->coverage,
                    'gaps' => count($snapshot->gaps),
                ])->summary('knowledge::runs.snapshot', [
                    'products' => number_format((int) ($snapshot->coverage['catalog']['products'] ?? 0)),
                    'known' => (string) ($snapshot->coverage['known_share'] ?? 0),
                    'gaps' => (string) count($snapshot->gaps),
                ]);
            },
        );
    }

    private function write(Shop $shop): KnowledgeSnapshot
    {
        $coverage = Census::coverage();

        return KnowledgeSnapshot::query()->updateOrCreate(
            ['shop_id' => $shop->id, 'taken_on' => today()],
            [
                'coverage' => $coverage,
                'freshness' => Census::freshness($shop->id),
                'gaps' => Census::gaps($coverage),
                'arrangement' => Census::arrangement(),
                'signals' => Census::signals(),
            ],
        );
    }
}
