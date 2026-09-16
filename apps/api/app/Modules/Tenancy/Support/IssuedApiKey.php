<?php

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Models\ShopApiKey;

/**
 * The one moment the plaintext key exists. Show it, then let it go.
 */
final readonly class IssuedApiKey
{
    public function __construct(
        public ShopApiKey $key,
        #[\SensitiveParameter]
        public string $plaintext,
    ) {}
}
