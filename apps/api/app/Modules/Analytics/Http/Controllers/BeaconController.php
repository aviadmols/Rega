<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Actions\RecordBeacon;
use App\Modules\Connections\Models\StoreConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /api/v1/widget/{site}/events — beacons from the storefront widget.
 *
 * Sent with navigator.sendBeacon as text/plain, so browsers make no preflight. The response
 * body is never read by the widget; status codes are for monitoring and tests.
 */
final class BeaconController
{
    private const MAX_BYTES = 64_000;

    public function __invoke(Request $request, RecordBeacon $record, string $site): Response|JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }

        if ($request->headers->has('Origin') && ! $connection->allowsOrigin($request->header('Origin'))) {
            return response()->json(['error' => 'other_origin'], 403);
        }

        $raw = (string) $request->getContent();

        if (strlen($raw) > self::MAX_BYTES) {
            return response()->json(['error' => 'too_large'], 413);
        }

        $result = $record->handle($connection, $raw);

        if ($result['problems'] !== []) {
            return response()->json(['error' => 'invalid', 'problems' => array_slice($result['problems'], 0, 3)], 422);
        }

        return response()->noContent(202);
    }
}
