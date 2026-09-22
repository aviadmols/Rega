<?php

namespace App\Modules\Shoppers\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A shopper who left a phone or an email. The contact is stored encrypted and never leaves the
 * panel; everything else about them is anonymous browsing linked through ShopperVisitor.
 *
 * @property int $id
 * @property string $shop_id
 * @property string $channel phone or email
 * @property string $contact_hash
 * @property string $contact the phone or email, encrypted at rest
 * @property string $contact_masked
 * @property Carbon|null $verified_at they proved the contact is theirs
 * @property Carbon|null $consented_at
 * @property string|null $consent_version the wording they agreed to
 * @property Carbon|null $last_seen_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ShopperIdentity extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'contact' => 'encrypted',
            'verified_at' => 'datetime',
            'consented_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return HasMany<ShopperVisitor, $this> */
    public function visitors(): HasMany
    {
        return $this->hasMany(ShopperVisitor::class, 'identity_id');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
