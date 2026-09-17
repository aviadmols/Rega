<?php

namespace App\Modules\Connections\Contracts;

use RuntimeException;

/**
 * The store could not be read. $reason is one of the connection failure codes
 * (invalid_token, locked_out, plugin_missing, woocommerce_inactive, http_error,
 * unexpected_response, unreachable), each with a translation under connections::runs.failures.
 */
final class StoreFeedUnavailable extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?int $httpStatus = null,
        string $detail = '',
    ) {
        parent::__construct(trim("{$reason} {$detail}"));
    }

    /** Translation key and parameters that explain the failure to a person. */
    public function summaryKey(): string
    {
        return "connections::runs.failures.{$this->reason}";
    }

    /** @return array<string, scalar> */
    public function summaryParams(): array
    {
        return ['status' => $this->httpStatus ?? 0];
    }
}
