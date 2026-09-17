<?php

namespace App\Modules\Analytics\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A widget section's score, see ComputeScores.
 *
 * @property int $id
 * @property string $shop_id
 * @property string $scope module, page_module or related
 * @property string $candidate the section, such as "complement"
 * @property string $page_type product or content, empty for a module
 * @property string $page_external_id
 * @property string $related_external_id the product shown inside the section, for related scores
 * @property int $exposures for related scores: how many times the section was opened on that page
 * @property int $opens
 * @property int $clicks
 * @property int $adds
 * @property int $purchases
 * @property int $value opens + 2 clicks + 4 adds + 8 purchases
 * @property string $score value per exposure, pulled toward the section's shop-wide rate when data is thin
 * @property Carbon $computed_at
 */
class AnalyticsScore extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    public const SCOPE_MODULE = 'module';

    public const SCOPE_PAGE = 'page_module';

    public const SCOPE_RELATED = 'related';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'exposures' => 'integer',
            'opens' => 'integer',
            'clicks' => 'integer',
            'adds' => 'integer',
            'purchases' => 'integer',
            'value' => 'integer',
            'score' => 'float',
            'computed_at' => 'datetime',
        ];
    }
}
