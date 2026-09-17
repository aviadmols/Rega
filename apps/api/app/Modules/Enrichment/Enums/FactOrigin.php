<?php

namespace App\Modules\Enrichment\Enums;

enum FactOrigin: string
{
    /** Code found it in the text and a model confirmed it applies. */
    case CodeAndModel = 'code+model';

    /** A model said it with a quote code could verify, but code did not find it on its own. */
    case Model = 'model';

    /** A person entered or corrected it. */
    case Person = 'person';

    public function label(): string
    {
        return __('enrichment::enrichment.origins.'.str_replace('+', '_', $this->value));
    }
}
