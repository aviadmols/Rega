<?php

namespace App\Modules\Shoppers\Http\Controllers;

use App\Core\Facades\Features;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Shoppers\Actions\ConfirmSignUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/widget/{site}/confirm   {"vid": "anon-…", "code": "123456"}
 *
 * Sent as text/plain from the storefront so the browser sends no preflight; the body is JSON.
 */
final class ConfirmController
{
    private const VISITOR_PATTERN = '/^anon-[A-Za-z0-9_-]{16,64}$/';

    public function __invoke(Request $request, ConfirmSignUp $confirm, string $site): JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }

        $origin = $request->header('Origin');

        if ($origin === null || ! $connection->allowsOrigin($origin)) {
            return response()->json(['error' => 'other_origin'], 403);
        }

        if (! Features::enabled('shoppers.signup', $connection->shop_id)) {
            return response()->json(['error' => 'not_enabled'], 403);
        }

        $body = json_decode((string) $request->getContent(), true);
        $visitor = is_array($body) ? (string) ($body['vid'] ?? '') : '';
        $code = is_array($body) ? (string) ($body['code'] ?? '') : '';

        if (! preg_match(self::VISITOR_PATTERN, $visitor) || ! preg_match('/^\d{4,8}$/', trim($code))) {
            return response()->json(['data' => ['status' => 'wrong_code']], 422);
        }

        $result = $confirm->handle($connection->shop_id, hash('sha256', $connection->shop_id.'|'.$visitor), $code);

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }
}
