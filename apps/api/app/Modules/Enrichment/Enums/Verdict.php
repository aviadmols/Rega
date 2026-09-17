<?php

namespace App\Modules\Enrichment\Enums;

enum Verdict: string
{
    case Ok = 'ok';
    case Wrong = 'wrong';
    case Unsure = 'unsure';

    public function label(): string
    {
        return __("enrichment::enrichment.verdicts.{$this->value}");
    }
}
