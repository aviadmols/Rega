<?php

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A credential the store plugin uses to talk to the API. Only a SHA-256 hash is stored;
 * the plaintext exists once, in the response that created it.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $name
 * @property string $prefix
 * @property string $hash
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 */
class ShopApiKey extends Model
{
    use HasUlids;

    public const PLAINTEXT_PREFIX = 'usk_';

    protected $fillable = ['name', 'prefix', 'hash'];

    protected $hidden = ['hash'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @param Builder<ShopApiKey> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public static function hashOf(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public static function findActiveByPlaintext(string $plaintext): ?self
    {
        if (! str_starts_with($plaintext, self::PLAINTEXT_PREFIX)) {
            return null;
        }

        return self::query()
            ->active()
            ->with('shop')
            ->where('hash', self::hashOf($plaintext))
            ->first();
    }
}
