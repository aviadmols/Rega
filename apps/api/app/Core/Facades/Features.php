<?php

namespace App\Core\Facades;

use App\Core\Features\FeatureManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled(string $key, ?string $shopId = null)
 * @method static bool enabledForCurrentShop(string $key)
 * @method static \App\Core\Settings\ValueSource source(string $key, ?string $shopId = null)
 * @method static bool|null overrideFor(string $key, ?string $shopId = null)
 * @method static void override(string $key, bool $enabled, ?string $shopId = null)
 * @method static void clearOverride(string $key, ?string $shopId = null)
 * @method static void purgeShop(string $shopId)
 *
 * @see FeatureManager
 */
final class Features extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FeatureManager::class;
    }
}
