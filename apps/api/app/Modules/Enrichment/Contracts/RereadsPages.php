<?php

namespace App\Modules\Enrichment\Contracts;

/**
 * Reads one page again, in code, right now.
 *
 * The readers normally walk a whole shop on a schedule. A person looking at one product or one
 * guide in the panel wants to see what a fresh reading would change on that page alone, without
 * waiting for the night and without paying for a model. This is that: the code readers that
 * apply to the page, run for it and nothing else, with what they wrote handed back.
 */
interface RereadsPages
{
    /**
     * @param  string  $type  product or content
     * @return array{written: int, kinds: array<string, int>, notes: list<string>}
     */
    public function reread(string $shopId, string $type, string $externalId): array;
}
