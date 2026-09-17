<?php

namespace App\Modules\Connections\Enums;

enum ConnectionStatus: string
{
    case Untested = 'untested';
    case Connected = 'connected';
    case Failed = 'failed';

    public function label(): string
    {
        return __("connections::connections.statuses.{$this->value}");
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
