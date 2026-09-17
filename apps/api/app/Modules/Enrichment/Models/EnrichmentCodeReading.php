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
 * What code read from one product on its own. See ReadProductsInCode.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $product_id
 * @property string|null $vocabulary_id
 * @property string|null $family_key
 * @property string|null $brand
 * @property array<string, mixed> $reading
 * @property string $reading_hash
 * @property Carbon $read_at
 */
class EnrichmentCodeReading extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reading' => 'array',
            'read_at' => 'datetime',
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

    /** @return BelongsTo<EnrichmentVocabulary, $this> */
    public function vocabulary(): BelongsTo
    {
        return $this->belongsTo(EnrichmentVocabulary::class, 'vocabulary_id');
    }
}
