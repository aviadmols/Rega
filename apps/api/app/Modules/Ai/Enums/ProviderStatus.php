<?php

namespace App\Modules\Ai\Enums;

enum ProviderStatus: string
{
    case Untested = 'untested';
    case Connected = 'connected';
    case Failed = 'failed';

    public function label(): string
    {
        return __("ai::providers.statuses.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::Untested => 'gray',
            self::Connected => 'success',
            self::Failed => 'danger',
        };
    }
}
