<?php

namespace App\Core\Tenancy;

/**
 * A screen that only means something inside one shop.
 *
 * A product, a fact about it, a guide, a superlative, a relation — each belongs to one store, and
 * a list of them from several stores at once is noise. Screens that carry this are simply not
 * there until a shop is chosen: gone from the navigation, and closed to anyone who types the
 * address. Screens about the platform itself — the shops, the people, the keys, the models — do
 * not carry it and are always available.
 */
trait NeedsShopContext
{
    public static function canAccess(...$arguments): bool
    {
        return app(TenantContext::class)->has() && parent::canAccess(...$arguments);
    }

    public static function shouldRegisterNavigation(...$arguments): bool
    {
        return app(TenantContext::class)->has() && parent::shouldRegisterNavigation(...$arguments);
    }
}
