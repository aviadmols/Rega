<?php

namespace App\Modules\Enrichment\Enums;

enum ItemStatus: string
{
    /** Sent, no answer yet. */
    case Pending = 'pending';

    /** The answer was read; its claims were saved, each with its own status. */
    case Applied = 'applied';

    /** The answer could not be used at all (not JSON, wrong product). */
    case Rejected = 'rejected';

    /** The product or article changed after the request was made; the answer is for old text. */
    case Stale = 'stale';
}
