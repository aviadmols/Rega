<?php

namespace App\Modules\Leads\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Leads\Enums\LeadGoal;
use App\Modules\Leads\Support\LeadRules;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What a shop wants from a reader, as a version.
 *
 * @property string $id
 * @property string $shop_id
 * @property int $version
 * @property LeadGoal $goal
 * @property string $offer
 * @property list<array{key: string, type: string, label: string, required: bool}> $fields
 * @property string $consent
 * @property string|null $promise
 * @property bool $active
 * @property int|null $created_by
 * @property Carbon $created_at
 */
class LeadFlow extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** The fields a flow may ask for. Anything else is a free line of text. */
    public const FIELD_TYPES = ['phone', 'email', 'name', 'text', 'choice'];

    protected $table = 'lead_flows';

    protected $guarded = ['id'];

    /** The flow a shop is running, or nothing when it has not set one up. */
    public static function inForce(string $shopId): ?self
    {
        return self::query()
            ->where('shop_id', $shopId)
            ->where('active', true)
            ->latest('version')
            ->first();
    }

    /** The rules the writer is held to. Not per-flow yet; the shop's, then the defaults. */
    public function rules(): array
    {
        return LeadRules::defaults();
    }

    /**
     * The fields to ask for, in order, with anything malformed dropped.
     *
     * @return list<array{key: string, type: string, label: string, required: bool}>
     */
    public function asked(): array
    {
        return array_values(array_filter(
            $this->fields,
            fn ($field): bool => is_array($field)
                && is_string($field['key'] ?? null)
                && in_array($field['type'] ?? '', self::FIELD_TYPES, true),
        ));
    }

    /** Whether this flow can finish at all: something to reach the person by. */
    public function canReachAnyone(): bool
    {
        foreach ($this->asked() as $field) {
            if (in_array($field['type'], ['phone', 'email'], true) && ($field['required'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    protected function casts(): array
    {
        return [
            'goal' => LeadGoal::class,
            'fields' => 'array',
            'active' => 'boolean',
        ];
    }
}
