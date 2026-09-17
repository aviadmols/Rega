<?php

namespace App\Modules\Enrichment\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A product to show next to an article, with why it was chosen.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $content_id
 * @property string $product_id
 * @property int $rank
 * @property int $score
 * @property array{mentioned?: bool, category?: string, shared_words?: int, superlatives?: int} $reasons
 * @property Carbon $computed_at
 */
class EnrichmentContentProduct extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rank' => 'integer',
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

    /** @return BelongsTo<CatalogContent, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(CatalogContent::class, 'content_id');
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class, 'product_id');
    }
}
