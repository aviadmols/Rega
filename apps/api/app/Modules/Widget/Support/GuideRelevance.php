<?php

namespace App\Modules\Widget\Support;

/**
 * Whether an article is a guide for a product, and how good a one, from what checkers approved
 * about the article (kind, value, categories, jobs) and the shop's category tree. Nothing here
 * knows a store or a product line.
 *
 * - Not guides: store pages, news, brand stories, articles without value to a shopper.
 * - An article tied to other jobs than the product's is about something else.
 * - An article whose categories are all in another department (bamboo for pine, roofing for a
 *   beam) is about other products.
 * - A material guide or a product review is about one branch: it needs the product's branch,
 *   so an ipe guide does not show on pine although both are wood.
 *
 * The rest is ranked: same branch, shared jobs, matched to the product, value to the shopper.
 */
final class GuideRelevance
{
    public const BRANCH = 'branch';

    public const DEPARTMENT = 'department';

    public const OTHER = 'other';

    public const UNKNOWN = 'unknown';

    private const NOT_GUIDES = ['store_page', 'news', 'brand_story', 'other'];

    private const ONE_BRANCH_KINDS = ['material_guide', 'product_review'];

    private const TOPIC_POINTS = [self::BRANCH => 3, self::DEPARTMENT => 1, self::UNKNOWN => 0];

    /** @param array<string, string|null> $parents category external id => parent external id */
    public function __construct(private readonly array $parents) {}

    /**
     * @param  list<string>  $productCategories
     * @param  list<string>  $productUses
     * @param  array{kind?: string|null, value?: string|null, categories?: list<string>, uses?: list<string>, matched?: bool}  $article
     */
    public function score(array $productCategories, array $productUses, array $article): ?int
    {
        $kind = $article['kind'] ?? null;
        $value = $article['value'] ?? null;
        $articleUses = $article['uses'] ?? [];
        $matched = (bool) ($article['matched'] ?? false);

        if (in_array($kind, self::NOT_GUIDES, true) || $value === 'none') {
            return null;
        }

        $shared = array_values(array_intersect($articleUses, $productUses));
        if ($productUses !== [] && $articleUses !== [] && $shared === []) {
            return null;
        }

        $topic = $this->topic($productCategories, $article['categories'] ?? []);
        if ($topic === self::OTHER || (in_array($kind, self::ONE_BRANCH_KINDS, true) && $topic !== self::BRANCH)) {
            return null;
        }

        // Something must tie the article to this product: its branch, a shared job or a match.
        if ($topic !== self::BRANCH && $shared === [] && ! $matched) {
            return null;
        }

        return self::TOPIC_POINTS[$topic]
            + 2 * count($shared)
            + ($matched ? 1 : 0)
            + match ($value) {
                'high' => 1,
                'low' => -1,
                default => 0,
            };
    }

    /**
     * BRANCH when the article and the product share a category below the top level, DEPARTMENT
     * when they share only a top-level category, OTHER when the article's categories are all
     * elsewhere, UNKNOWN when the article has none.
     *
     * @param  list<string>  $productCategories
     * @param  list<string>  $articleCategories
     */
    public function topic(array $productCategories, array $articleCategories): string
    {
        if ($articleCategories === []) {
            return self::UNKNOWN;
        }

        // Each path without its top level: an article on "pine" shares "pine" with a product in
        // "pine > treated", while "wood > for building" shares only "wood" with it.
        $branches = [];
        $roots = [];
        foreach ($productCategories as $id) {
            $chain = $this->chain($id);
            $roots[] = array_pop($chain);
            array_push($branches, ...$chain);
        }

        $department = false;
        foreach ($articleCategories as $id) {
            $chain = $this->chain($id);
            $root = array_pop($chain);

            if (array_intersect($chain, $branches) !== []) {
                return self::BRANCH;
            }

            $department = $department || in_array($root, $roots, true);
        }

        return $department ? self::DEPARTMENT : self::OTHER;
    }

    /** @return list<string> the category, then its parents up to the top level */
    public function chain(string $id): array
    {
        $chain = [$id];

        while (($parent = $this->parents[end($chain)] ?? null) !== null && ! in_array($parent, $chain, true)) {
            $chain[] = $parent;
        }

        return $chain;
    }
}
