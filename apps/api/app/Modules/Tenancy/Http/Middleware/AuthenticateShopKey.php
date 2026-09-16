<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Tenancy\Models\ShopApiKey;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a store (its plugin or app) by API key and puts its shop into the tenant
 * context for the rest of the request.
 *
 * Accepts the key as "X-Shop-Key: usk_..." or "Authorization: Bearer usk_...".
 * Errors are stable machine-readable codes, because the plugin shows them to the merchant
 * in its own language.
 *
 * Implements AuthenticatesRequests so Laravel's middleware priority runs it before
 * ThrottleRequests. Without that, the limiter runs first, sees no key, and every shop
 * behind the same IP shares one limit.
 */
final class AuthenticateShopKey implements AuthenticatesRequests
{
    public const HEADER = 'X-Shop-Key';

    /** Writing last_used_at on every request would turn every read into a write. */
    private const TOUCH_INTERVAL_SECONDS = 300;

    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plaintext = $request->header(self::HEADER) ?: $request->bearerToken();

        if (! is_string($plaintext) || $plaintext === '') {
            return $this->error('missing_key', 401);
        }

        $key = ShopApiKey::findActiveByPlaintext($plaintext);

        if ($key === null || $key->shop === null) {
            return $this->error('invalid_key', 401);
        }

        $shop = $key->shop;

        if (! $shop->isActive()) {
            return $this->error('shop_inactive', 403);
        }

        if (! Features::enabled('tenancy.api_access', $shop->id)) {
            return $this->error('api_disabled', 403);
        }

        if ($key->last_used_at === null || $key->last_used_at->diffInSeconds(now()) > self::TOUCH_INTERVAL_SECONDS) {
            ShopApiKey::query()->whereKey($key->id)->update(['last_used_at' => now()]);
        }

        $this->tenant->set($shop->id);
        $request->attributes->set('shop', $shop);
        $request->attributes->set('shop_api_key', $key);

        return $next($request);
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json(['error' => $code], $status);
    }
}
