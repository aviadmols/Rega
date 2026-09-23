<?php

namespace App\Modules\Knowledge\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Enrichment\Models\EnrichmentContentRules;
use App\Modules\Enrichment\Models\EnrichmentContentTemplate;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Enums\Vertical;
use App\Modules\Tenancy\Models\Shop;

/**
 * What several shops of a trade discovered separately becomes the trade's.
 *
 * This is the only place anything crosses from one shop to another, and it is deliberately narrow.
 * A marker word reaches a trade's template when shops arrived at it independently — each through
 * its own articles, its own audit, its own two models agreeing. One shop finding something is a
 * quirk of that shop's writer. Three shops finding the same thing is how the trade writes.
 *
 * What travels is a word that introduces a conclusion. What never travels is a product, a price,
 * a question a shopper asked, or anything at all about a person. There is nothing here that could
 * carry them: the only thing read from a shop is the marker lists it published.
 */
final class PromoteToVertical
{
    public const AGENT = 'knowledge.promoter';

    public const ACTION = 'knowledge.promote';

    /** The lists a trade can learn. The same three a shop's audit may add to. */
    private const LISTS = ['takeaway_markers', 'takeaway_phrases', 'audience_markers'];

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: null,
            work: function (RunContext $run): void {
                $promoted = [];

                foreach (Vertical::all() as $vertical) {
                    $added = $this->promote($vertical);

                    if ($added !== []) {
                        $promoted[$vertical->value] = $added;
                    }
                }

                $run->output(['promoted' => $promoted])
                    ->summary('knowledge::runs.promoted', [
                        'words' => (string) array_sum(array_map(fn (array $lists): int => array_sum(array_map('count', $lists)), $promoted)),
                        'trades' => (string) count($promoted),
                    ]);
            },
        );
    }

    /**
     * @return array<string, list<string>> what this trade gained, by list
     */
    private function promote(Vertical $vertical): array
    {
        $shops = Shop::query()->where('vertical', $vertical->value)->pluck('id');
        $need = (int) Settings::get('knowledge.promote_min_shops');

        if ($shops->count() < $need) {
            return [];
        }

        // How many shops published each word, counting a shop once however often it says it.
        $seenIn = [];

        foreach ($shops as $shopId) {
            // A shop that only inherited the trade's rules has not found anything, so it does
            // not get to vote for them.
            $published = $this->tenant->run($shopId, fn () => EnrichmentContentRules::query()
                ->where('shop_id', $shopId)->where('active', true)->latest('version')->first());

            if ($published === null) {
                continue;
            }

            $rules = $published->rules;

            foreach (self::LISTS as $list) {
                foreach (array_unique((array) ($rules[$list] ?? [])) as $word) {
                    $seenIn[$list][(string) $word] = ($seenIn[$list][(string) $word] ?? 0) + 1;
                }
            }
        }

        $current = EnrichmentContentTemplate::inForce($vertical->value) ?? EnrichmentContentRules::defaults();
        $added = [];
        $next = $current;

        foreach (self::LISTS as $list) {
            $have = (array) ($current[$list] ?? []);

            foreach ($seenIn[$list] ?? [] as $word => $shopsWithIt) {
                if ($shopsWithIt >= $need && ! in_array($word, $have, true)) {
                    $added[$list][] = $word;
                    $have[] = $word;
                }
            }

            $next[$list] = array_values($have);
        }

        if ($added === []) {
            return [];
        }

        $next['version'] = (int) ($current['version'] ?? 1) + 1;

        EnrichmentContentTemplate::query()->where('vertical', $vertical->value)->update(['active' => false]);
        EnrichmentContentTemplate::query()->create([
            'vertical' => $vertical->value,
            'version' => $next['version'],
            'rules' => $next,
            'rules_hash' => hash('sha256', (string) json_encode($next, JSON_UNESCAPED_UNICODE)),
            'author' => 'promotion',
            'active' => true,
        ]);

        return $added;
    }

    /** Whether a trade may learn from its shops at all. */
    public static function allowed(): bool
    {
        return Features::enabled('knowledge.promote');
    }
}
