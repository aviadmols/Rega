<?php

namespace App\Modules\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * How a panel tends to do across the shops of one trade. Belongs to no shop, so it is not scoped.
 *
 * @property int $id
 * @property string $vertical
 * @property string $candidate
 * @property int $shops
 * @property int $exposures
 * @property int $opens
 * @property int $clicks
 * @property float $score
 * @property Carbon $computed_at
 */
class AnalyticsPrior extends Model
{
    public $timestamps = false;

    protected $table = 'analytics_priors';

    protected $guarded = ['id'];

    /**
     * What a shop of this trade should expect, best first.
     *
     * @return array<string, float> candidate => score
     */
    public static function forVertical(?string $vertical): array
    {
        if ($vertical === null || $vertical === '') {
            return [];
        }

        return self::query()
            ->where('vertical', $vertical)
            ->orderByDesc('score')
            ->pluck('score', 'candidate')
            ->map(fn ($score): float => (float) $score)
            ->all();
    }

    protected function casts(): array
    {
        return ['computed_at' => 'datetime', 'score' => 'float'];
    }
}
