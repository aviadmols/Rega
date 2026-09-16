<?php

namespace App\Core\Tenancy;

use Illuminate\Database\Eloquent\Model;

/**
 * For every model that holds one shop's data. Requires a string `shop_id` column.
 *
 * Reads are filtered to the current shop. Creates take the current shop when shop_id is
 * not set explicitly, and fail when there is no shop to take.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            if (blank($model->getAttribute('shop_id'))) {
                $model->setAttribute('shop_id', app(TenantContext::class)->require());
            }
        });
    }
}
