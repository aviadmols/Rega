<?php

namespace App\Modules\Admin\Http\Middleware;

use App\Core\Tenancy\TenantContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In the merchant panel, the shop in the URL becomes the tenant context, so every
 * shop-owned model in every module is filtered to it without each screen remembering to.
 */
final class SyncTenantFromPanel
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $shop = Filament::getTenant();

        if ($shop !== null) {
            $this->tenant->set((string) $shop->getKey());
        }

        return $next($request);
    }
}
