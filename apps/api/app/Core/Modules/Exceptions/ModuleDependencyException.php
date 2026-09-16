<?php

namespace App\Core\Modules\Exceptions;

use RuntimeException;

final class ModuleDependencyException extends RuntimeException
{
    public static function missing(string $module, string $dependency): self
    {
        return new self("Module [{$module}] requires [{$dependency}], which does not exist or is disabled.");
    }

    /** @param list<string> $path */
    public static function cycle(array $path): self
    {
        return new self('Module dependency cycle: '.implode(' -> ', $path));
    }
}
