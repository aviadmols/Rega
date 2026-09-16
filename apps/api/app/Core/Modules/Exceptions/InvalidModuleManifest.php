<?php

namespace App\Core\Modules\Exceptions;

use RuntimeException;

final class InvalidModuleManifest extends RuntimeException
{
    public static function because(string $file, string $reason): self
    {
        return new self("Invalid module manifest [{$file}]: {$reason}");
    }
}
