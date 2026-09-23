<?php

namespace App\Modules\Enrichment\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Enrichment\Support\ContentRules;
use App\Modules\Tenancy\Models\Shop;
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

    /**
     * What the reader should use for this shop.
     *
     * Its own published rules first, because a shop that has learned something about itself knows
     * better than its trade. Then what its trade has learned, so a shop opened tomorrow starts
     * where the others got to. Then the defaults, so there is always something.
     *
     * Inherited, not copied: a shop with no rules of its own follows its trade as the trade
     * improves, without anybody re-importing anything.
     */
    public static function inForce(string $shopId): array
    {
        $own = self::query()->where('shop_id', $shopId)->where('active', true)->latest('version')->first();

        if ($own !== null) {
            return ContentRules::withDefaults($own->rules);
        }

        $shop = Shop::query()->find($shopId);
        $trade = EnrichmentContentTemplate::inForce($shop?->vertical?->value);

        return $trade === null ? ContentRules::defaults() : ContentRules::withDefaults($trade);
    }

    public static function versionInForce(string $shopId): int
    {
        return (int) (self::inForce($shopId)['version'] ?? ContentRules::VERSION);
    }
}
