<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentContentProduct;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentRanking;
use App\Modules\Enrichment\Scanning\TextNormalizer;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Chooses the products to show next to each article, in code, from what agents found and
 * checkers approved. No model is called here.
 *
 *   a product the article links to ..................... first, always
 *   a product in a category a checker approved ......... more specific category first
 *   words the product title shares with the article .... breaks ties within a category
 *   superlatives the product holds ...................... a small boost
 *
 * Only products in stock that can be bought. Articles a checker found useless to shoppers
 * (store pages, value "none") get no products. At most three products per category, so an
 * article about decks and pergolas shows both.
 */
final class MatchProductsToArticles
{
    public const AGENT = 'enrichment.matcher';

    public const ACTION = 'enrichment.match_article_products';

    private const MENTIONED = 1000;

    private const PER_CATEGORY_LEVEL = 10;

    private const PER_SHARED_WORD = 4;

    private const MAX_SHARED_WORDS = 5;

    private const PER_SUPERLATIVE = 3;

    private const MAX_PER_CATEGORY = 3;

    private const STOPWORDS = ['של', 'עם', 'או', 'את', 'על', 'גם', 'כל', 'לכל', 'מבית', 'דגם', 'כולל', 'בלבד', 'מתוצרת', 'אינץ', 'the', 'and', 'for', 'with'];

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(string $shopId): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->match($run, $shopId)),
        );
    }

    private function match(RunContext $run, string $shopId): void
    {
        $limit = (int) Settings::get('enrichment.max_products_per_article', $shopId);
        $categories = CatalogCategory::query()->whereNull('removed_at')->get();
        $byExternalId = $categories->keyBy('external_id');
        $superlatives = EnrichmentRanking::query()->get(['product_id'])->countBy('product_id');
        $now = now();
        $rows = [];
        $articles = 0;
        $skipped = 0;

        foreach (CatalogContent::query()->whereNull('removed_at')->orderBy('external_id')->get() as $article) {
            $facts = EnrichmentFact::query()->where('content_id', $article->id)->where('status', FactStatus::Approved)->get();
            $value = $facts->firstWhere('kind', FactKind::ShopperValue)?->value_text;
            $kind = $facts->firstWhere('kind', FactKind::ContentKind)?->value_text;
            $mentioned = array_values(array_map('strval', (array) $article->product_external_ids));

            if ($value === 'none' || $kind === 'store_page' || ($value === null && $mentioned === [])) {
                $skipped++;

                continue;
            }

            $candidates = $this->candidates($article, $facts, $mentioned, $categories, $byExternalId, $superlatives);
            $chosen = $this->choose($candidates, $limit);

            foreach ($chosen as $rank => $candidate) {
                $rows[] = [
                    'shop_id' => $shopId,
                    'content_id' => $article->id,
                    'product_id' => $candidate['product']->id,
                    'rank' => $rank + 1,
                    'score' => $candidate['score'],
                    'reasons' => $candidate['reasons'],
                    'computed_at' => $now,
                ];
            }

            $articles += $chosen === [] ? 0 : 1;
        }

        DB::transaction(function () use ($rows): void {
            EnrichmentContentProduct::query()->delete();

            foreach ($rows as $row) {
                EnrichmentContentProduct::query()->create($row);
            }
        });

        $run->output(['articles' => $articles, 'links' => count($rows), 'skipped' => $skipped])
            ->summary('enrichment::runs.article_products_matched', [
                'articles' => number_format($articles),
                'links' => number_format(count($rows)),
                'skipped' => number_format($skipped),
            ]);
    }

    /**
     * @param  Collection<int, EnrichmentFact>  $facts
     * @param  list<string>  $mentioned
     * @param  Collection<int, CatalogCategory>  $categories
     * @param  Collection<string, CatalogCategory>  $byExternalId
     * @param  Collection<string, int>  $superlatives
     * @return list<array{product: CatalogProduct, score: int, reasons: array<string, mixed>, group: string}>
     */
    private function candidates(CatalogContent $article, Collection $facts, array $mentioned, Collection $categories, Collection $byExternalId, Collection $superlatives): array
    {
        $articleWords = $this->words($article->title.' '.$article->title.' '.$article->body);
        $candidates = [];

        $consider = function (CatalogProduct $product, int $base, array $reasons, string $group) use (&$candidates, $articleWords, $superlatives): void {
            $shared = min(self::MAX_SHARED_WORDS, count(array_intersect($this->words($product->title), $articleWords)));
            $held = (int) $superlatives->get($product->id, 0);
            $score = $base + $shared * self::PER_SHARED_WORD + $held * self::PER_SUPERLATIVE;

            if (isset($candidates[$product->id]) && $candidates[$product->id]['score'] >= $score) {
                return;
            }

            $candidates[$product->id] = [
                'product' => $product,
                'score' => $score,
                'reasons' => array_filter($reasons + ['shared_words' => $shared, 'superlatives' => $held]),
                'group' => $group,
            ];
        };

        if ($mentioned !== []) {
            foreach ($this->buyable()->whereIn('external_id', $mentioned)->get() as $product) {
                $consider($product, self::MENTIONED, ['mentioned' => true], 'mentioned');
            }
        }

        foreach ($facts->where('kind', FactKind::Category) as $fact) {
            $category = $byExternalId->get((string) $fact->value_text);

            if ($category === null) {
                continue;
            }

            $base = self::PER_CATEGORY_LEVEL * (count($category->path) + 1);

            foreach ($this->buyable()->inCategories($category->branchExternalIds($categories))->get() as $product) {
                $consider($product, $base, ['category' => $category->pathLabel()], $category->external_id);
            }
        }

        return array_values($candidates);
    }

    /**
     * @param  list<array{product: CatalogProduct, score: int, reasons: array<string, mixed>, group: string}>  $candidates
     * @return list<array{product: CatalogProduct, score: int, reasons: array<string, mixed>, group: string}>
     */
    private function choose(array $candidates, int $limit): array
    {
        usort($candidates, fn (array $a, array $b): int => [$b['score'], (int) $a['product']->external_id] <=> [$a['score'], (int) $b['product']->external_id]);

        $chosen = [];
        $perGroup = [];

        foreach ($candidates as $candidate) {
            $group = $candidate['group'];

            if ($group !== 'mentioned' && ($perGroup[$group] ?? 0) >= self::MAX_PER_CATEGORY) {
                continue;
            }

            $chosen[] = $candidate;
            $perGroup[$group] = ($perGroup[$group] ?? 0) + 1;

            if (count($chosen) >= $limit) {
                break;
            }
        }

        return $chosen;
    }

    /** @return Builder<CatalogProduct> */
    private function buyable()
    {
        return CatalogProduct::query()->whereNull('removed_at')->where('in_stock', true)->where('purchasable', true);
    }

    /** @return list<string> meaningful words, three letters or more */
    private function words(?string $text): array
    {
        $words = preg_split('~[^\p{L}\d]+~u', TextNormalizer::forMatching((string) $text)) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            fn (string $w): bool => mb_strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true) && ! ctype_digit($w),
        )));
    }
}
