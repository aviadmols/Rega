<?php

namespace App\Modules\Leads\Contracts;

/**
 * What a page offers a reader, for whoever is drawing the page.
 *
 * The widget must not know how a call to action is chosen, only that there is one and which
 * version it is showing — the version matters because every click is recorded against it and the
 * learning is what decides between them.
 */
interface OffersCallsToAction
{
    /**
     * @return array{id: string, variant: string, headline: string, body: string, button: string}|null
     */
    public function forPage(string $shopId, string $pageType, string $externalId, string $locale): ?array;
}
