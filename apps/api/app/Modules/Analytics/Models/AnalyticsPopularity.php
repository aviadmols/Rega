<?php

namespace App\Modules\Analytics\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * How often one product was added to the cart and ordered lately, see ComputePopularity.
 *
 * @property int $id
 * @property string $shop_id
 * @property string $product_external_id
 * @property int $adds times added to the cart, from the store's own button or the widget
 * @property int $orders orders that contained the product
 * @property int $units pieces ordered
 * @property int $score adds + 2 × orders
 * @property int $rank 1 for the shop's most wanted product
 * @property bool $popular in the top share of the shop's catalog, with enough activity
 * @property int $window_days
 * @property Carbon $computed_at
 */
class AnalyticsPopularity extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'analytics_popularity';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'adds' => 'integer',
            'orders' => 'integer',
            'units' => 'integer',
            'score' => 'integer',
            'rank' => 'integer',
            'popular' => 'boolean',
            'window_days' => 'integer',
            'computed_at' => 'datetime',
        ];
    }
}
