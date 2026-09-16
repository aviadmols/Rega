<?php

namespace App\Core\Settings;

enum SettingType: string
{
    case Int = 'int';
    case Float = 'float';
    case Bool = 'bool';
    case String = 'string';
    case Enum = 'enum';
}
