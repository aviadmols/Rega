<?php

namespace App\Modules\Tenancy\Exceptions;

use RuntimeException;

final class ApiKeyLimitReached extends RuntimeException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct(__('tenancy::api_keys.errors.limit_reached', ['limit' => $limit]));
    }
}
