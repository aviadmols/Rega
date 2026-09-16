<?php

namespace App\Core\Settings;

use RuntimeException;

final class UnknownSetting extends RuntimeException
{
    public static function named(string $key): self
    {
        return new self("Setting [{$key}] is not declared by any enabled module.");
    }

    public static function notShopScoped(string $key): self
    {
        return new self("Setting [{$key}] is global and cannot be overridden per shop.");
    }
}
