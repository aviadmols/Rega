<?php

namespace App\Modules\Runs\Enums;

enum RunTrigger: string
{
    /** An operator or merchant pressed a button. */
    case Manual = 'manual';

    /** The scheduler started it. */
    case Schedule = 'schedule';

    /** A store told us something changed. */
    case Webhook = 'webhook';

    /** Another run started it, as a step of a larger job. */
    case System = 'system';

    public function label(): string
    {
        return __("runs::runs.triggers.{$this->value}");
    }
}
