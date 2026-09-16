<?php

namespace App\Core\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits every query on a shop-owned model to the shop in the tenant context.
 *
 * With no shop and no deliberate unscoped mode it throws instead of returning every shop's
 * rows. A missing filter is a data leak; a thrown exception is a bug report.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isUnscoped()) {
            return;
        }

        $shopId = $context->id() ?? throw MissingTenantContext::forModel($model::class);

        $builder->where($model->qualifyColumn('shop_id'), $shopId);
    }
}
