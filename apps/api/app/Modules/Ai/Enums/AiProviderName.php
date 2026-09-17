<?php

namespace App\Modules\Ai\Enums;

enum AiProviderName: string
{
    case OpenAi = 'openai';
    case Anthropic = 'anthropic';

    public function label(): string
    {
        return match ($this) {
            self::OpenAi => 'OpenAI',
            self::Anthropic => 'Anthropic (Claude)',
        };
    }

    /** What this provider does in Rega. See docs/ADR/0003. */
    public function role(): string
    {
        return __("ai::providers.roles.{$this->value}");
    }
}
