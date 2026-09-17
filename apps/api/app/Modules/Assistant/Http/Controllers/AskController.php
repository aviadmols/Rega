<?php

namespace App\Modules\Assistant\Http\Controllers;

use App\Modules\Assistant\Actions\AnswerQuestion;
use App\Modules\Connections\Models\StoreConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/widget/{site}/ask   {"id": "<product id>", "question": "...", "vid": "anon-...", "locale": "he"}
 *
 * Sent as text/plain from the storefront so the browser sends no preflight; the body is JSON.
 */
final class AskController
{
    private const ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/';

    private const VISITOR_PATTERN = '/^anon-[A-Za-z0-9_-]{16,64}$/';

    public function __invoke(Request $request, AnswerQuestion $answer, string $site): JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }

        $origin = $request->header('Origin');

        if ($origin === null || ! $connection->allowsOrigin($origin)) {
            return response()->json(['error' => 'other_origin'], 403);
        }

        $body = json_decode((string) $request->getContent(), true);
        $id = is_array($body) ? (string) ($body['id'] ?? '') : '';
        $question = is_array($body) ? (string) ($body['question'] ?? '') : '';
        $visitor = is_array($body) ? (string) ($body['vid'] ?? '') : '';
        $locale = is_array($body) && ($body['locale'] ?? 'he') === 'en' ? 'en' : 'he';

        if (! preg_match(self::ID_PATTERN, $id) || ! preg_match(self::VISITOR_PATTERN, $visitor) || $question === '') {
            return response()->json(['error' => 'invalid_question'], 422);
        }

        // The visitor only counts toward a daily limit, as a hash salted per shop, like analytics.
        $result = $answer->handle($connection->shop_id, $id, $question, hash('sha256', $connection->shop_id.'|'.$visitor), $locale);

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }
}
