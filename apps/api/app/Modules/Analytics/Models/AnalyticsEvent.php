<?php

namespace App\Modules\Analytics\Models;

use App\Core\Facades\Settings;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $shop_id
 * @property string $event_id
 * @property string $type
 * @property string $page_type
 * @property string $page_path
 * @property string|null $product_external_id the product page it happened on
 * @property string|null $content_external_id the article it happened on
 * @property string|null $item_external_id the product an add to cart or an answer is about
 * @property string|null $model
 * @property string|null $candidate_id
 * @property string|null $slot
 * @property string|null $source
 * @property string|null $result
 * @property int|null $quantity
 * @property string $visitor_hash
 * @property string $session_id
 * @property bool $preview
 * @property bool $holdout
 * @property Carbon $occurred_at
 */
class AnalyticsEvent extends Model
{
    use BelongsToTenant;
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'preview' => 'boolean',
            'holdout' => 'boolean',
            'quantity' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return Builder<AnalyticsEvent> */
    public function prunable(): Builder
    {
        return static::query()->withoutGlobalScopes()->where('occurred_at', '<', now()->subDays((int) Settings::get('analytics.retention_days')));
    }
}
