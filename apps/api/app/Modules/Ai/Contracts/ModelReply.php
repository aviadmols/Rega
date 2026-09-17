<?php

namespace App\Modules\Ai\Contracts;

/** A model's JSON answer and what it used. */
final class ModelReply
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly array $data,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
    ) {}

    /** The cost at prices per million tokens. */
    public function costUsd(float $inputPerMillion, float $outputPerMillion): float
    {
        return ($this->inputTokens * $inputPerMillion + $this->outputTokens * $outputPerMillion) / 1_000_000;
    }
}
