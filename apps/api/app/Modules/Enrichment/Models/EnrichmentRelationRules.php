<?php

namespace App\Modules\Enrichment\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Enrichment\Relations\RelationRuleSet;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a shop's rules for what goes with what. The newest active version is used.
 *
 * @property string $id
 * @property string $shop_id
 * @property int $version
 * @property array<string, mixed> $definition
 * @property string $definition_hash
 * @property string|null $author
 * @property bool $active
 */
class EnrichmentRelationRules extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'version' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function rules(): RelationRuleSet
    {
        return RelationRuleSet::fromTrusted($this->definition);
    }
}
