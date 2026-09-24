<?php

namespace App\Modules\Leads\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Somebody who asked to be contacted. Encrypted at rest, masked on screen.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $flow_id
 * @property string $page_type
 * @property string $page_external_id
 * @property string|null $cta_id
 * @property string $channel
 * @property string $contact_hash
 * @property string $contact
 * @property string $contact_masked
 * @property array<string, string>|null $answers
 * @property Carbon $consented_at
 * @property string $consent_wording
 * @property int $quality
 * @property string $status
 * @property string|null $visitor_hash
 * @property int|null $seen_by
 * @property Carbon|null $seen_at
 */
class Lead extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** Nobody has looked at it yet. */
    public const NEW = 'new';

    /** Somebody on the team has it. */
    public const HANDLED = 'handled';

    /** A test, a duplicate, or a person who asked to be forgotten. */
    public const DISCARDED = 'discarded';

    protected $table = 'leads';

    protected $guarded = ['id'];

    /** @return BelongsTo<LeadFlow, $this> */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(LeadFlow::class, 'flow_id');
    }

    protected function casts(): array
    {
        return [
            'contact' => 'encrypted',
            'answers' => 'encrypted:array',
            'consented_at' => 'datetime',
            'seen_at' => 'datetime',
        ];
    }
}
