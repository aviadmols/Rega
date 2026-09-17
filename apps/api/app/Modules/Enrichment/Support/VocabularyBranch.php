<?php

namespace App\Modules\Enrichment\Support;

use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use Illuminate\Database\Eloquent\Builder;

/** The active products in one vocabulary's branch of the catalog, outside its excluded categories. */
final class VocabularyBranch
{
    /** @return Builder<CatalogProduct> */
    public static function products(VocabularyDefinition $vocabulary): Builder
    {
        $categories = CatalogCategory::query()->whereNull('removed_at')->get();
        $root = $categories->firstWhere('external_id', $vocabulary->rootCategoryExternalId());

        if ($root === null) {
            return CatalogProduct::query()->whereRaw('1 = 0');
        }

        $excluded = [];
        foreach ($vocabulary->excludedCategoryExternalIds() as $externalId) {
            $category = $categories->firstWhere('external_id', $externalId);
            $excluded = [...$excluded, ...($category?->branchExternalIds($categories) ?? [$externalId])];
        }

        return CatalogProduct::query()
            ->whereNull('removed_at')
            ->inCategories(array_values(array_diff($root->branchExternalIds($categories), $excluded)))
            ->when($excluded !== [], fn (Builder $q) => $q->whereDoesntHave('categories', fn (Builder $c) => $c->whereIn('catalog_categories.external_id', $excluded)));
    }
}
