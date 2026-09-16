<?php

namespace App\Modules\Tenancy\Models;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Localization\Locales;
use App\Modules\Tenancy\Database\Factories\ShopFactory;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Enums\ShopStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A store connected to the system. The root every other shop-owned record points at.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property ShopPlatform $platform
 * @property string $domain
 * @property string $content_locale
 * @property string $currency
 * @property string $timezone
 * @property ShopStatus $status
 */
class Shop extends Model
{
    /** @use HasFactory<ShopFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'name',
        'slug',
        'platform',
        'domain',
        'content_locale',
        'currency',
        'timezone',
        'status',
    ];

    protected $attributes = [
        'content_locale' => 'he',
        'currency' => 'ILS',
        'timezone' => 'Asia/Jerusalem',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'platform' => ShopPlatform::class,
            'status' => ShopStatus::class,
        ];
    }

    protected static function booted(): void
    {
        // The kernel's override tables have no foreign key to shops, so clean them here.
        static::deleted(function (Shop $shop): void {
            Features::purgeShop($shop->id);
            Settings::purgeShop($shop->id);
        });
    }

    protected static function newFactory(): ShopFactory
    {
        return ShopFactory::new();
    }

    /** @return HasMany<ShopApiKey, $this> */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ShopApiKey::class);
    }

    public function isActive(): bool
    {
        return $this->status === ShopStatus::Active;
    }

    /** "rtl" or "ltr" for the language visitors see. */
    public function contentDirection(): string
    {
        return Locales::direction($this->content_locale);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
