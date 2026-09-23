<?php

namespace App\Modules\Assistant\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/widget/{site}/questions?id=<page id>&type=product|content&locale=he
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
        $onArticle = $request->query('type') === 'content';

        if (! preg_match(self::ID_PATTERN, $id)) {
            return response()->json(['error' => 'invalid_page'], 422);
        }

        $shopId = $connection->shop_id;

        if (! Features::enabled($onArticle ? 'assistant.on_content' : 'assistant.on_products', $shopId)) {
            return response()->json(['data' => ['enabled' => false, 'suggested' => [], 'recent' => []]]);
        }

        $data = $tenant->run($shopId, function () use ($shopId, $id, $locale, $onArticle): array {
            $page = $onArticle
                ? CatalogContent::query()->active()->where('external_id', $id)->first()
                : CatalogProduct::query()->whereNull('removed_at')->where('external_id', $id)->first();
            $answered = $page === null ? collect() : AssistantAnswer::query()
                ->where($onArticle ? 'content_id' : 'product_id', $page->id)
                ->where('outcome', AssistantAnswer::ANSWERED)
                ->where('status', AssistantAnswer::SHOWN)
                ->get(['question', 'answer', 'source', 'asked_count', 'last_asked_at']);

            $limit = (int) Settings::get('assistant.suggested_questions', $shopId);
            // What this page itself is worth being asked, then what shoppers have asked here, then
            // the questions any page of this kind can answer. Specific before general, always.
            $suggested = collect($page === null ? [] : self::worthAsking($page->id, $onArticle, $locale))
                ->concat($answered->sortByDesc('asked_count')->pluck('question'))
                ->concat((array) __($onArticle ? 'assistant::questions.article' : 'assistant::questions.common', [], $locale))
                ->unique()->take($limit)->values()->all();

            return [
                'enabled' => true,
                'suggested' => $suggested,
                'recent' => $answered->sortByDesc('last_asked_at')->take(self::RECENT)
                    ->map(fn (AssistantAnswer $a): array => array_filter(['question' => $a->question, 'answer' => (string) $a->answer, 'source' => $a->source]))->values()->all(),
            ];
        });

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }

    /**
     * The questions this page was found to be worth being asked, worded for the reader.
     *
     * The scan stored them as kinds and subjects rather than sentences, so the same reading can
     * be put to a Hebrew reader and an English one without being read twice.
     *
     * @return list<string>
     */
    private static function worthAsking(string $pageId, bool $onArticle, string $locale): array
    {
        if (! $onArticle) {
            return [];
        }

        $asks = EnrichmentFact::query()
            ->where('content_id', $pageId)
            ->where('kind', FactKind::Tag)
            ->where('status', FactStatus::Approved)
            ->where('key', 'like', 'ask%')
            ->orderBy('key')
            ->get(['key', 'value_text', 'value_number', 'quote']);

        return $asks->map(function (EnrichmentFact $ask) use ($locale): ?string {
            $kind = (string) $ask->quote;
            $line = (string) __('assistant::questions.asked.'.$kind, [
                'term' => (string) $ask->value_text,
                'count' => (string) (int) $ask->value_number,
            ], $locale);

            return str_contains($line, 'assistant::') ? null : $line;
        })->filter()->values()->all();
    }
}
