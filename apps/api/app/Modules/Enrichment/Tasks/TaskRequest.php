<?php

namespace App\Modules\Enrichment\Tasks;

final class TaskRequest
{
    /**
     * @param  array<string, mixed>  $request  sent to the model
     * @param  array<string, mixed>  $context  kept to check the answer
     */
    public function __construct(
        public readonly string $customId,
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly string $inputHash,
        public readonly array $request,
        public readonly array $context,
    ) {}
}
