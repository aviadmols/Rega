<?php

namespace App\Modules\Enrichment\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A computed position: rank 1 of set_size by metric, within one comparable set of products.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $product_id
 * @property string $metric price or a spec key
 * @property string $direction min or max
 * @property int $rank
 * @property bool $tied
 * @property int $set_size
 * @property string $set_key
 * @property array<string, string> $set_facets
 * @property string $value
 * @property string|null $unit
 * @property Carbon $computed_at
 */
class EnrichmentRanking extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'tied' => 'boolean',
            'set_size' => 'integer',
            'set_facets' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class, 'product_id');
    }
}
