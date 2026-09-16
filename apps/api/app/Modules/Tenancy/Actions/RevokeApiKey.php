<?php

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Models\ShopApiKey;

final class RevokeApiKey
{
    public function handle(ShopApiKey $key): ShopApiKey
    {
        if (! $key->isRevoked()) {
            $key->forceFill(['revoked_at' => now()])->save();
        }

        return $key;
    }
}
