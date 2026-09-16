<?php

namespace App\Modules\Tenancy\Actions;

use App\Core\Facades\Settings;
use App\Modules\Tenancy\Exceptions\ApiKeyLimitReached;
use App\Modules\Tenancy\Models\Shop;
use App\Modules\Tenancy\Models\ShopApiKey;
use App\Modules\Tenancy\Support\IssuedApiKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class IssueApiKey
{
    /**
     * @throws ApiKeyLimitReached when the shop already has as many active keys as its cap allows
     */
    public function handle(Shop $shop, string $name): IssuedApiKey
    {
        return DB::transaction(function () use ($shop, $name): IssuedApiKey {
            // Lock the shop row, not the keys: Postgres refuses FOR UPDATE on a count, and
            // locking the parent serialises concurrent issuing for the same shop.
            Shop::query()->whereKey($shop->getKey())->lockForUpdate()->first();

            $limit = (int) Settings::get('tenancy.max_active_api_keys', $shop->id);

            if ($shop->apiKeys()->active()->count() >= $limit) {
                throw new ApiKeyLimitReached($limit);
            }

            $prefix = Str::lower(Str::random(8));
            $plaintext = ShopApiKey::PLAINTEXT_PREFIX.$prefix.'_'.Str::random(40);

            $key = $shop->apiKeys()->create([
                'name' => $name,
                'prefix' => ShopApiKey::PLAINTEXT_PREFIX.$prefix,
                'hash' => ShopApiKey::hashOf($plaintext),
            ]);

            return new IssuedApiKey($key, $plaintext);
        });
    }
}
