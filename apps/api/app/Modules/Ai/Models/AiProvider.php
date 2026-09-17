<?php

namespace App\Modules\Ai\Models;

use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Ai\Enums\ProviderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An API key for a model provider, shared by every shop. Per-shop keys and model bindings per
 * role come with the LLM router.
 *
 * @property string $id
 * @property AiProviderName $provider
 * @property string $api_key decrypted on read
 * @property string|null $key_hint e.g. "sk-…a1b2", safe to show
 * @property ProviderStatus $status
 * @property string|null $last_error_code
 * @property Carbon|null $last_checked_at
 * @property list<array{id: string, name: string, created_at: ?string}>|null $models
 */
class AiProvider extends Model
{
    use HasUlids;

    protected $fillable = ['provider', 'api_key'];

    protected $hidden = ['api_key'];

    protected $attributes = [
        'status' => 'untested',
    ];

    protected function casts(): array
    {
        return [
            'provider' => AiProviderName::class,
            'api_key' => 'encrypted',
            'status' => ProviderStatus::class,
            'last_checked_at' => 'datetime',
            'models' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (AiProvider $provider): void {
            if ($provider->isDirty('api_key')) {
                $key = trim((string) $provider->api_key);
                $provider->api_key = $key;
                $provider->key_hint = self::hint($key);
                $provider->status = ProviderStatus::Untested;
                $provider->last_error_code = null;
                $provider->models = null;
            }
        });
    }

    /** "sk-ant-api03-…a1b2" becomes "sk-ant-…a1b2": enough to recognise a key, useless to use it. */
    public static function hint(string $key): string
    {
        $head = substr($key, 0, 8);
        $dash = strrpos($head, '-');
        $prefix = $dash === false ? '' : substr($head, 0, $dash + 1);

        return $prefix.'…'.substr($key, -4);
    }

    public static function for(AiProviderName $name): ?self
    {
        return self::query()->where('provider', $name)->first();
    }
}
