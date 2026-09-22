<?php

namespace App\Modules\Shoppers\Contracts;

/**
 * What one shopper looked at. The Widget asks this for the "products you viewed" circle, and the
 * operator panel for the sign-ups page.
 */
interface VisitHistory
{
    /**
     * The products this visitor viewed most, most visits first.
     *
     * A shopper who signed up and proved the contact is theirs gets the browsing of every browser
     * they proved; anyone else gets only their own. The window is longer once they signed up.
     *
     * @return list<array{id: string, views: int, last_at: string}>
     */
    public function topProducts(string $shopId, string $visitorHash, int $limit, ?string $except = null): array;

    /**
     * The same for one shopper in the panel, across every browser that signed up with their contact.
     *
     * @return list<array{id: string, views: int, last_at: string}>
     */
    public function topProductsForIdentity(string $shopId, int $identityId, int $limit): array;

    /**
     * Whether this visitor signed up, and whether they proved the contact is theirs.
     *
     * @return array{channel: string, masked: string, verified: bool}|null null when they did not
     */
    public function state(string $shopId, string $visitorHash): ?array;
}
