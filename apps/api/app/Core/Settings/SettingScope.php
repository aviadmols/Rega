<?php

namespace App\Core\Settings;

enum SettingScope: string
{
    /** One value for the whole system. Only the operator changes it. */
    case Global = 'global';

    /** A global value that any single shop can override. */
    case Shop = 'shop';
}
