<?php

namespace App\Core\Features\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string $scope "*" for the global value, otherwise a shop id
 * @property bool $enabled
 */
final class FeatureOverride extends Model
{
    protected $table = 'feature_overrides';

    protected $fillable = ['key', 'scope', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
