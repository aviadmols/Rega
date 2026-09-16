<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/shop — "who am I connected as?". The plugin calls it to test the connection.
 */
final class ShowShopController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        return response()->json([
            'data' => [
                'id' => $shop->id,
                'name' => $shop->name,
                'slug' => $shop->slug,
                'platform' => $shop->platform->value,
                'domain' => $shop->domain,
                'content_locale' => $shop->content_locale,
                'direction' => $shop->contentDirection(),
                'currency' => $shop->currency,
                'timezone' => $shop->timezone,
                'status' => $shop->status->value,
            ],
        ]);
    }
}
