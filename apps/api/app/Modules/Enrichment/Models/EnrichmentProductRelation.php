<?php

namespace App\Modules\Enrichment\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\RelationKind;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A product to show with another one, and why. See ComputeProductRelations.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $product_id
 * @property string $related_product_id
 * @property RelationKind $kind
 * @property string $source a rule key, "merchant", "merchant_reverse", "family" or "same_type"
 * @property int $score
 * @property array<string, mixed> $reasons
 * @property Carbon $computed_at
 */
class EnrichmentProductRelation extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => RelationKind::class,
            'score' => 'integer',
            'reasons' => 'array',
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

    /** @return BelongsTo<CatalogProduct, $this> */
    public function related(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class, 'related_product_id');
    }
}
