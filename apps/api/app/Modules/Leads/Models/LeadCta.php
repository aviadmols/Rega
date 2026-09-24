<?php

namespace App\Modules\Leads\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One version of what a page offers a reader.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $page_type
 * @property string $page_external_id
 * @property string $variant
 * @property string $headline
 * @property string $body
 * @property string $source
 * @property string|null $model
 * @property int|null $score
 * @property array<string, mixed>|null $review
 * @property bool $active
 * @property Carbon $composed_at
 */
class LeadCta extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'lead_ctas';

    protected $guarded = ['id'];

    /**
     * What this page can offer, best first.
     *
     * A version a model wrote and a reviewer liked comes before one a template produced, because
     * the template is the floor rather than the aim. Among equals, the older one first, so the
     * order does not shuffle under a reader between page loads.
     *
     * @return Builder<self>
     */
    public static function forPage(string $pageType, string $externalId)
    {
        return self::query()
            ->where('page_type', $pageType)
            ->where('page_external_id', $externalId)
            ->where('active', true)
            ->orderByDesc('score')
            ->orderBy('composed_at');
    }

    protected function casts(): array
    {
        return ['review' => 'array', 'active' => 'boolean', 'composed_at' => 'datetime'];
    }
}
