<?php

namespace App\Modules\Enrichment\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $shop_id
 * @property string $key
 * @property int $version
 * @property string $root_category_external_id
 * @property array<string, mixed> $definition
 * @property string $definition_hash
 * @property string|null $author
 * @property bool $active
 * @property int|null $created_by
 */
class EnrichmentVocabulary extends Model
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

    public function definition(): VocabularyDefinition
    {
        return VocabularyDefinition::fromTrusted($this->definition);
    }

    public function title(): string
    {
        return $this->definition()->name(app()->getLocale()).' · v'.$this->version;
    }
}
