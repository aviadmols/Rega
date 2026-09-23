<?php

namespace App\Modules\Admin\Support;

use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\Session;

/**
 * The shop an operator is currently looking at.
 *
 * The operator panel can see every shop, which is the point of it and also its problem: products,
 * facts, vocabularies, superlatives, relations and runs from several stores in one list say very
 * little. So the operator picks a shop and it stays picked, in the session, across every screen
 * and between visits.
 *
 * The choice is not a filter each screen has to remember to apply. EnterOperatorScope puts the
 * shop into TenantContext, so every shop-owned model is narrowed by the same global scope that
 * protects a merchant, and a screen written without a thought for tenancy is still correct.
 * "Every shop" stays available on purpose, for the few questions that really span the platform.
 */
final class CurrentShop
{
    public const SESSION_KEY = 'operator.shop';

    /** The value the picker uses for "every shop": empty is "nothing chosen" to a select. */
    public const EVERY = 'all';

    /** The chosen shop, or null while the operator is looking across every shop. */
    public static function id(): ?string
    {
        $id = Session::get(self::SESSION_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    public static function shop(): ?Shop
    {
        $id = self::id();

        return $id === null ? null : Shop::query()->find($id);
    }

    /** Picking a shop that no longer exists, or "every shop", clears the choice. */
    public static function set(?string $id): void
    {
        $id = $id === self::EVERY ? null : $id;

        if ($id === null || ! Shop::query()->whereKey($id)->exists()) {
            Session::forget(self::SESSION_KEY);

            return;
        }

        Session::put(self::SESSION_KEY, $id);
    }

    /**
     * Shops to choose from, "every shop" first.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [self::EVERY => __('admin::panels.shop.every')] + Shop::query()->orderBy('name')->pluck('name', 'id')->all();
    }
}
