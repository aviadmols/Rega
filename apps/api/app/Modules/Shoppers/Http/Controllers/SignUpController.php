<?php

namespace App\Modules\Shoppers\Http\Controllers;

use App\Core\Facades\Features;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Shoppers\Actions\SignUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/widget/{site}/signup   {"vid": "anon-…", "contact": "0501234567", "consent": true, "locale": "he"}
 *
 * Sent as text/plain from the storefront so the browser sends no preflight; the body is JSON.
 */
final class SignUpController
{
    private const VISITOR_PATTERN = '/^anon-[A-Za-z0-9_-]{16,64}$/';

    public function __invoke(Request $request, SignUp $signUp, string $site): JsonResponse
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
        $contact = is_array($body) ? (string) ($body['contact'] ?? '') : '';
        $consent = is_array($body) && ($body['consent'] ?? false) === true;
        $locale = is_array($body) && ($body['locale'] ?? 'he') === 'en' ? 'en' : 'he';

        if (! preg_match(self::VISITOR_PATTERN, $visitor)) {
            return response()->json(['error' => 'invalid_visitor'], 422);
        }

        $result = $signUp->handle(
            $connection->shop_id,
            hash('sha256', $connection->shop_id.'|'.$visitor),
            $contact,
            $consent,
            $locale,
        );

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }
}
