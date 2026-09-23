<?php

namespace App\Modules\Enrichment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The reading rules a trade shares. Not owned by any shop, so not scoped to one.
 *
 * @property string $id
 * @property string $vertical
 * @property int $version
 * @property array<string, mixed> $rules
 * @property string $rules_hash
 * @property string|null $author
 * @property bool $active
 */
class EnrichmentContentTemplate extends Model
{
    use HasUlids;

    protected $table = 'enrichment_content_templates';

    protected $guarded = ['id'];

    /** What a shop of this trade inherits, or nothing when the trade has learned nothing yet. */
    public static function inForce(?string $vertical): ?array
    {
        if ($vertical === null || $vertical === '') {
            return null;
        }

        return self::query()
            ->where('vertical', $vertical)
            ->where('active', true)
            ->latest('version')
            ->first()?->rules;
    }

    protected function casts(): array
    {
        return ['rules' => 'array', 'active' => 'boolean'];
    }
}
