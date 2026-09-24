<?php

namespace App\Modules\Leads\Actions;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Leads\Contracts\OffersCallsToAction;
use App\Modules\Leads\Models\LeadCta;

/**
 * The version of a page's offer to show this time.
 *
 * Best first, where best means what the reviewer thought of it until enough readers have had
 * their say, at which point the learning takes over through the ordinary scores. One version at a
 * time, because a page with three banners on it has no banner on it.
 */
final class OfferCallToAction implements OffersCallsToAction
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function forPage(string $shopId, string $pageType, string $externalId, string $locale): ?array
    {
        if (! Features::enabled('leads.enabled', $shopId)) {
            return null;
        }

        $cta = $this->tenant->run($shopId, function () use ($pageType, $externalId): ?LeadCta {
            $versions = LeadCta::forPage($pageType, $externalId)->get();

            if ($versions->isEmpty()) {
                return null;
            }

            // What readers did, where enough of them have done it. A version nobody has seen
            // enough of keeps its place in the order and gets its turn: a page that only ever
            // shows its best guess never finds out it was wrong.
            $rates = LearnFromReaders::rates();

            if ($rates === []) {
                return $versions->first();
            }

            return $versions->sortByDesc(fn (LeadCta $version): float => $rates[$version->variant] ?? PHP_FLOAT_MAX)->first();
        });

        if ($cta === null) {
            return null;
        }

        return [
            'id' => $cta->id,
            'variant' => $cta->variant,
            'headline' => $cta->headline,
            'body' => $cta->body,
            'button' => (string) __('leads::cta.button', [], $locale),
        ];
    }
}
