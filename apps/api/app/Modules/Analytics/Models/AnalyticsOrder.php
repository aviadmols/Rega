<?php

namespace App\Modules\Analytics\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $shop_id
 * @property string $order_ref a hash of the store's order number
 * @property string $total
 * @property string $currency
 * @property int $items_count
 * @property list<array{product_id: string, quantity: int, total: float}> $items
 * @property string|null $visitor_hash
 * @property bool $assisted the visitor used the widget before ordering
 * @property string $attributed_total the part of the order added to the cart from the widget
 * @property list<string>|null $attributed_items
 * @property Carbon $ordered_at
 */
class AnalyticsOrder extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'attributed_items' => 'array',
            'assisted' => 'boolean',
            'total' => 'decimal:2',
            'attributed_total' => 'decimal:2',
            'items_count' => 'integer',
            'ordered_at' => 'datetime',
        ];
    }
}
