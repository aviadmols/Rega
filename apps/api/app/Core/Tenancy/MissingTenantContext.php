<?php

namespace App\Core\Tenancy;

use RuntimeException;

final class MissingTenantContext extends RuntimeException
{
    public static function create(): self
    {
        return new self('No shop is set in the tenant context.');
    }

    public static function forModel(string $model): self
    {
        return new self(
            "Queried [{$model}] without a shop in the tenant context. Set the shop with TenantContext::run(), "
            .'or use TenantContext::runUnscoped() when a cross-shop query is intended.'
        );
    }
}
