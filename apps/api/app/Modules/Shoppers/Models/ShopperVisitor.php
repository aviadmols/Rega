<?php

namespace App\Modules\Shoppers\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One anonymous visitor (a browser) that belongs to a shopper.
 *
 * @property int $id
 * @property string $shop_id
 * @property string $visitor_hash
 * @property int $identity_id
 * @property bool $verified the person proved this contact with a code from this browser
 * @property Carbon $linked_at
 */
class ShopperVisitor extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            'linked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ShopperIdentity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(ShopperIdentity::class, 'identity_id');
    }
}
