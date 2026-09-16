<?php

namespace App\Core\Settings\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string $scope "*" for the global value, otherwise a shop id
 * @property int|float|bool|string $value
 */
final class SettingOverride extends Model
{
    protected $table = 'setting_overrides';

    protected $fillable = ['key', 'scope', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
