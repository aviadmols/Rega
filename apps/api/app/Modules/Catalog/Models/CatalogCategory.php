<?php

namespace App\Modules\Catalog\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $shop_id
 * @property string $external_id
 * @property string|null $parent_external_id
 * @property string $name
 * @property list<string> $path names from the root category to this one
 * @property int $depth
 * @property int $product_count
 * @property string|null $url
 * @property string|null $image_url
 * @property string $hash
 * @property Carbon|null $synced_at
 * @property Carbon|null $removed_at
 */
class CatalogCategory extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'path' => 'array',
            'depth' => 'integer',
            'product_count' => 'integer',
            'synced_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return BelongsToMany<CatalogProduct, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(CatalogProduct::class, 'catalog_category_product', 'category_id', 'product_id');
    }

    /** @param Builder<CatalogCategory> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    public function pathLabel(): string
    {
        return implode(' › ', $this->path);
    }

    /**
     * External IDs of this category and every category below it, from the given list of a
     * shop's categories. Used to scope work to a branch of the catalog.
     *
     * @param  iterable<CatalogCategory>  $categories
     * @return list<string>
     */
    public function branchExternalIds(iterable $categories): array
    {
        $children = [];
        foreach ($categories as $category) {
            if ($category->parent_external_id !== null) {
                $children[$category->parent_external_id][] = $category->external_id;
            }
        }

        $branch = [];
        $queue = [$this->external_id];

        while ($queue !== []) {
            $id = array_shift($queue);

            if (in_array($id, $branch, true)) {
                continue;
            }

            $branch[] = $id;
            $queue = [...$queue, ...($children[$id] ?? [])];
        }

        return $branch;
    }
}
