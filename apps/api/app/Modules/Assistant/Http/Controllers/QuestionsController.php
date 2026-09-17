<?php

namespace App\Modules\Assistant\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/widget/{site}/questions?id=<product id>&locale=he
 *
 * What the question box shows before the shopper types: questions to tap (the ones shoppers ask
 * most about this product, then common ones) and answers earlier shoppers got. Answers only;
 * nothing about who asked.
 */
final class QuestionsController
{
    private const ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/';

    private const RECENT = 5;

    public function __invoke(Request $request, TenantContext $tenant, string $site): JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }

        $origin = $request->header('Origin');

        if ($origin !== null && ! $connection->allowsOrigin($origin)) {
            return response()->json(['error' => 'other_origin'], 403);
        }

        $id = (string) $request->query('id');
        $locale = $request->query('locale') === 'en' ? 'en' : 'he';

        if (! preg_match(self::ID_PATTERN, $id)) {
            return response()->json(['error' => 'invalid_page'], 422);
        }

        $shopId = $connection->shop_id;

        if (! Features::enabled('assistant.on_products', $shopId)) {
            return response()->json(['data' => ['enabled' => false, 'suggested' => [], 'recent' => []]]);
        }

        $data = $tenant->run($shopId, function () use ($shopId, $id, $locale): array {
            $product = CatalogProduct::query()->whereNull('removed_at')->where('external_id', $id)->first();
            $answered = $product === null ? collect() : AssistantAnswer::query()
                ->where('product_id', $product->id)
                ->where('outcome', AssistantAnswer::ANSWERED)
                ->where('status', AssistantAnswer::SHOWN)
                ->get(['question', 'answer', 'asked_count', 'last_asked_at']);

            $limit = (int) Settings::get('assistant.suggested_questions', $shopId);
            $suggested = $answered->sortByDesc('asked_count')->pluck('question')
                ->concat((array) __('assistant::questions.common', [], $locale))
                ->unique()->take($limit)->values()->all();

            return [
                'enabled' => true,
                'suggested' => $suggested,
                'recent' => $answered->sortByDesc('last_asked_at')->take(self::RECENT)
                    ->map(fn (AssistantAnswer $a): array => ['question' => $a->question, 'answer' => (string) $a->answer])->values()->all(),
            ];
        });

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
