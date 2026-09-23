<?php

namespace App\Modules\Knowledge\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What was known about one shop on one day.
 *
 * @property string $id
 * @property string $shop_id
 * @property Carbon $taken_on
 * @property array<string, mixed> $coverage
 * @property array<string, mixed> $freshness
 * @property array<string, mixed> $gaps
 * @property array<string, mixed> $arrangement
 * @property array<string, mixed> $signals
 */
class KnowledgeSnapshot extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'knowledge_snapshots';

    protected $guarded = ['id'];

    /** The most recent snapshot, and the newest one at least this many days older than it. */
    public static function latestAnd(string $shopId, int $daysBack): array
    {
        $latest = self::query()->where('shop_id', $shopId)->latest('taken_on')->first();

        if ($latest === null) {
            return [null, null];
        }

        $earlier = self::query()
            ->where('shop_id', $shopId)
            ->whereDate('taken_on', '<=', $latest->taken_on->copy()->subDays($daysBack))
            ->latest('taken_on')
            ->first();

        return [$latest, $earlier];
    }

    protected function casts(): array
    {
        return [
            'taken_on' => 'date',
            'coverage' => 'array',
            'freshness' => 'array',
            'gaps' => 'array',
            'arrangement' => 'array',
            'signals' => 'array',
        ];
    }
}
