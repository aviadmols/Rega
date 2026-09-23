<?php

namespace App\Modules\Enrichment\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What one audit found in a handful of articles, and what it proposes doing about it.
 *
 * Nothing here changes how anything reads until a person publishes it. The sample, the findings,
 * the proposal and the second model's verdict are all kept, so a published change can be read
 * back to the articles that caused it.
 *
 * @property string $id
 * @property string $shop_id
 * @property int $from_version
 * @property string $status
 * @property array<int, mixed> $sampled
 * @property array<int, mixed> $findings
 * @property array<string, mixed>|null $proposed
 * @property array<string, mixed>|null $review
 * @property string|null $summary
 * @property string|null $run_id
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 */
class EnrichmentRuleProposal extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** The reviewer has not spoken yet. */
    public const PENDING = 'pending';

    /** The reviewer agreed it is a good change; it is waiting for a person. */
    public const APPROVED = 'approved';

    /** The reviewer did not agree, and says why. */
    public const REJECTED = 'rejected';

    /** A person published it, and it is now a version of the rules. */
    public const PUBLISHED = 'published';

    /** A person looked and said no. */
    public const DISCARDED = 'discarded';

    protected $table = 'enrichment_rule_proposals';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sampled' => 'array',
            'findings' => 'array',
            'proposed' => 'array',
            'review' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    /** Only an approved proposal is a thing a person may publish. */
    public function publishable(): bool
    {
        return $this->status === self::APPROVED;
    }
}
