<?php

namespace App\Modules\Widget\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Shoppers\Contracts\VisitHistory;
use App\Modules\Widget\Support\ProductCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/widget/{site}/recent   {"vid": "anon-…", "id": "<the product they are on>"}
 *
 * The products this shopper looked at most, for the "products you viewed" circle. One visitor's
 * own browsing, so it can never be part of the page bank, which is cached and shared. Sent as
 * text/plain so the browser sends no preflight, and with the visitor in the body, not the URL.
 */
final class RecentController
{
    private const VISITOR_PATTERN = '/^anon-[A-Za-z0-9_-]{16,64}$/';

    private const ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/';

    public function __invoke(Request $request, VisitHistory $history, TenantContext $tenant, string $site): JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }

        $origin = $request->header('Origin');

        if ($origin === null || ! $connection->allowsOrigin($origin)) {
            return response()->json(['error' => 'other_origin'], 403);
        }

        $shopId = $connection->shop_id;

        if (! Features::enabled('shoppers.recent_products', $shopId)) {
            return response()->json(['error' => 'not_enabled'], 403);
        }

        $body = json_decode((string) $request->getContent(), true);
        $visitor = is_array($body) ? (string) ($body['vid'] ?? '') : '';
        $except = is_array($body) ? (string) ($body['id'] ?? '') : '';
        $locale = is_array($body) && ($body['locale'] ?? 'he') === 'en' ? 'en' : 'he';

        if (! preg_match(self::VISITOR_PATTERN, $visitor)) {
            return response()->json(['error' => 'invalid_visitor'], 422);
        }

        $hash = hash('sha256', $shopId.'|'.$visitor);
        $limit = (int) Settings::get('shoppers.recent_products_count', $shopId);
        $viewed = $history->topProducts($shopId, $hash, $limit, preg_match(self::ID_PATTERN, $except) ? $except : null);

        $data = [
            'products' => $this->cards($tenant, $shopId, $viewed, $locale),
            'signed_up' => $history->state($shopId, $hash),
        ];

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store, private');
    }

    /**
     * @param  list<array{id: string, views: int, last_at: string}>  $viewed
     * @return list<array<string, mixed>>
     */
    private function cards(TenantContext $tenant, string $shopId, array $viewed, string $locale): array
    {
        if ($viewed === []) {
            return [];
        }

        $order = array_column($viewed, 'id');
        $views = array_column($viewed, 'views', 'id');

        return $tenant->run($shopId, function () use ($order, $views, $locale): array {
            $products = CatalogProduct::query()->active()->whereIn('external_id', $order)->get()
                ->sortBy(fn (CatalogProduct $p): int => (int) array_search($p->external_id, $order, true))
                ->values();

            // Under each card, how many times they opened it: the reason it is on this list.
            $reasons = $products->mapWithKeys(fn (CatalogProduct $p): array => [
                $p->id => trans_choice('widget::bank.recent_views', $views[$p->external_id] ?? 1, ['count' => $views[$p->external_id] ?? 1], $locale),
            ]);

            return ProductCard::many($products, $reasons);
        });
    }
}
