<?php

namespace App\Core\Features;

use RuntimeException;

final class UnknownFeature extends RuntimeException
{
    public static function named(string $key): self
    {
        return new self("Feature [{$key}] is not declared by any enabled module.");
    }
}
