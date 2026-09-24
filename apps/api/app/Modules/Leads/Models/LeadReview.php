<?php

namespace App\Modules\Leads\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One score a reviewing model gave, and what readers made of that line afterwards.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $cta_id
 * @property string $writer
 * @property string $reviewer
 * @property int $score
 * @property list<string> $reasons
 * @property int $exposures
 * @property int $clicks
 * @property int $leads
 * @property Carbon|null $measured_at
 * @property Carbon $reviewed_at
 */
class LeadReview extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'lead_reviews';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reasons' => 'array', 'reviewed_at' => 'datetime', 'measured_at' => 'datetime'];
    }
}
