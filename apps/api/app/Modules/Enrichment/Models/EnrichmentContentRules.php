<?php

namespace App\Modules\Enrichment\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Enrichment\Support\ContentRules;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The marker words one shop's article reader looks for, as a version.
 *
 * A version is only ever added. The one in force is the active one, and undoing a change is
 * making an older version active again rather than editing anything, so what the reader was
 * doing on any given day stays answerable.
 *
 * @property string $id
 * @property string $shop_id
 * @property int $version
 * @property array<string, mixed> $rules
 * @property string $rules_hash
 * @property string|null $author
 * @property bool $active
 * @property int|null $created_by
 * @property Carbon $created_at
 */
class EnrichmentContentRules extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'enrichment_content_rules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rules' => 'array', 'active' => 'boolean'];
    }

    /** What the reader should use for this shop: its own active version, or the defaults. */
    public static function inForce(string $shopId): array
    {
        $active = self::query()->where('shop_id', $shopId)->where('active', true)->latest('version')->first();

        return $active === null ? ContentRules::defaults() : ContentRules::withDefaults($active->rules);
    }

    public static function versionInForce(string $shopId): int
    {
        return (int) (self::inForce($shopId)['version'] ?? ContentRules::VERSION);
    }
}
