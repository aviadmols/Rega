<?php

namespace App\Modules\Ai\Support;

use RuntimeException;
use Throwable;

/**
 * An expected failure while checking a key: wrong key, no permission, rate limit, provider
 * down, network. The code maps to a translated explanation.
 */
final class ProviderCheckFailed extends RuntimeException
{
    public const INVALID_KEY = 'invalid_key';

    public const PERMISSION_DENIED = 'permission_denied';

    public const RATE_LIMITED = 'rate_limited';

    public const PROVIDER_ERROR = 'provider_error';

    public const UNREACHABLE = 'unreachable';

    public function __construct(public readonly string $reason, public readonly ?int $httpStatus = null, ?Throwable $previous = null)
    {
        parent::__construct($previous?->getMessage() ?? $reason, 0, $previous);
    }

    public static function fromStatus(?int $status, ?Throwable $previous = null): self
    {
        return new self(match (true) {
            $status === 401 => self::INVALID_KEY,
            $status === 403 => self::PERMISSION_DENIED,
            $status === 429 => self::RATE_LIMITED,
            default => self::PROVIDER_ERROR,
        }, $status, $previous);
    }
}
