<?php

namespace App\Modules\Admin\Enums;

enum ShopRole: string
{
    case Owner = 'owner';
    case Member = 'member';

    public function label(): string
    {
        return __("admin::users.roles.{$this->value}");
    }
}
