<?php

namespace App\Modules\Admin\Http\Middleware;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Support\CurrentShop;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which shop the operator panel is looking at, for the whole request.
 *
 * With a shop chosen, the panel works inside it: the same tenant scope that keeps a merchant to
 * their own store keeps these screens to the chosen one, so a list of facts or superlatives is
 * about one store and means something. Choosing "every shop" goes back to looking across the
 * platform, which the operator panel is also allowed to do, on purpose.
 */
final class EnterOperatorScope
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $shop = CurrentShop::id() ?? CurrentShop::theOnlyShop();

        // A shop deleted since it was picked leaves the panel across every shop, not broken.
        if ($shop !== null && CurrentShop::exists($shop)) {
            CurrentShop::set($shop);
            $this->tenant->set($shop);
        } else {
            CurrentShop::set(null);
            // Said out loud rather than relied on: a request gets a fresh context, but going back
            // to every shop must drop the last one even when the instance is reused.
            $this->tenant->clear();
            $this->tenant->enterUnscoped();
        }

        return $next($request);
    }
}
