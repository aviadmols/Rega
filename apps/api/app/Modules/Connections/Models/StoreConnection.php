<?php

namespace App\Modules\Connections\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * How Rega reaches one store: the site address and the token its Rega plugin issued.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $platform
 * @property string $site_url
 * @property string $access_token decrypted on read
 * @property string|null $token_prefix
 * @property ConnectionStatus $status
 * @property string|null $last_error_code
 * @property Carbon|null $last_checked_at
 * @property array<string, mixed>|null $site_info the plugin's /status response
 */
class StoreConnection extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['shop_id', 'platform', 'site_url', 'access_token'];

    protected $hidden = ['access_token'];

    protected $attributes = [
        'platform' => 'woocommerce',
        'status' => 'untested',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'status' => ConnectionStatus::class,
            'last_checked_at' => 'datetime',
            'site_info' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (StoreConnection $connection): void {
            $connection->site_url = rtrim(trim($connection->site_url), '/');

            if ($connection->isDirty('access_token')) {
                $connection->token_prefix = mb_substr((string) $connection->access_token, 0, 10);
                $connection->status = ConnectionStatus::Untested;
                $connection->last_error_code = null;
            }
        });
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** A value from the last status response, e.g. "woocommerce.version". */
    public function info(string $path, mixed $default = null): mixed
    {
        return Arr::get($this->site_info ?? [], $path, $default);
    }
}
