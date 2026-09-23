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

        return is_string($id) && $id !== '' && $id !== self::EVERY ? $id : null;
    }

    /**
     * Whether the operator has said anything at all. "Every shop" is an answer, so it is kept
     * like any other: without this, a platform with one shop would pull itself back into that
     * shop the moment the operator asked to step out of it.
     */
    public static function chosen(): bool
    {
        return is_string(Session::get(self::SESSION_KEY));
    }

    /**
     * The shop the panel is actually inside: the one chosen, or — while nothing has been said and
     * the platform has a single shop — that one, so a new operator does not meet a panel with most
     * of its screens missing and read it as a fault. Once they answer, their answer stands, even
     * when the answer is "every shop".
     */
    public static function effective(): ?string
    {
        if (self::chosen()) {
            return self::id();
        }

        $shops = Shop::query()->orderBy('name')->limit(2)->pluck('id');

        return $shops->count() === 1 ? (string) $shops->first() : null;
    }

    public static function shop(): ?Shop
    {
        $id = self::id();

        return $id === null ? null : Shop::query()->find($id);
    }

    public static function exists(string $id): bool
    {
        return Shop::query()->whereKey($id)->exists();
    }

    /** "Every shop", or a shop that no longer exists, is remembered as looking across them all. */
    public static function set(?string $id): void
    {
        $across = $id === null || $id === self::EVERY || ! self::exists($id);

        Session::put(self::SESSION_KEY, $across ? self::EVERY : $id);
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
