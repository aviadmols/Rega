<?php

namespace App\Core\Settings;

use InvalidArgumentException;

final class InvalidSettingValue extends InvalidArgumentException
{
    /**
     * @param  string  $reason  translation key under core::settings.errors
     * @param  array<string, scalar>  $replace
     */
    private function __construct(
        public readonly string $key,
        public readonly string $reason,
        public readonly array $replace = [],
    ) {
        parent::__construct("Invalid value for setting [{$key}]: {$reason}");
    }

    /** @param array<string, scalar> $replace */
    public static function for(string $key, string $reason, array $replace = []): self
    {
        return new self($key, $reason, $replace);
    }

    public function translated(): string
    {
        return __("core::settings.errors.{$this->reason}", $this->replace);
    }
}
