<?php

namespace App\Modules\Knowledge\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One week's answer to whether the learning helped.
 *
 * @property string $id
 * @property string $shop_id
 * @property Carbon $taken_on
 * @property int $window_days
 * @property array<string, int> $learned
 * @property array<string, int> $control
 * @property array<string, mixed> $effect
 * @property string $verdict
 */
class LearningMeasurement extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** The learned order did better than the untouched one. */
    public const HELPED = 'helped';

    /** It did worse, which is what the control group is there to catch. */
    public const HURT = 'hurt';

    /** Enough to judge by, and the two sides are the same. */
    public const NO_DIFFERENCE = 'no_difference';

    /** Not enough on one side or the other yet. The honest answer for a young shop. */
    public const TOO_EARLY = 'too_early';

    /** Nobody is being held out, so there is nothing to compare against. */
    public const NO_CONTROL = 'no_control';

    protected $table = 'learning_measurements';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'taken_on' => 'date',
            'learned' => 'array',
            'control' => 'array',
            'effect' => 'array',
        ];
    }
}
