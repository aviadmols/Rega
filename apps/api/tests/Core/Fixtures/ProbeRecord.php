<?php

namespace Tests\Core\Fixtures;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A stand-in for any shop-owned model in any module.
 *
 * @property int $id
 * @property string $shop_id
 * @property string $label
 */
final class ProbeRecord extends Model
{
    use BelongsToTenant;

    protected $table = 'probe_records';

    protected $fillable = ['shop_id', 'label'];

    public $timestamps = false;
}
