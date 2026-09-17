<?php

namespace App\Modules\Enrichment\Enums;

/**
 * The jobs agents do in enrichment. Each has its own prompt, request shape and checks.
 * The model for each is the cheapest that does the job: bulk reading goes to the smallest.
 */
enum TaskType: string
{
    /** Reads products: type, which measurement is which spec, which choices and tags are true. */
    case ProductExtraction = 'product_extraction';

    /** A second model checks claims the first could not settle alone. */
    case FactReview = 'fact_review';

    /** Reads articles: what kind, how useful to a shopper, which categories they help with. */
    case ContentMapping = 'content_mapping';

    /** Writes what a shopper should know about a product, each point resting on a quote from its text. */
    case ProductHighlights = 'product_highlights';

    public function label(): string
    {
        return __("enrichment::enrichment.tasks.{$this->value}");
    }

    public function agent(): string
    {
        return match ($this) {
            self::ProductExtraction => 'enrichment.extractor',
            self::FactReview => 'enrichment.reviewer',
            self::ContentMapping => 'enrichment.content_mapper',
            self::ProductHighlights => 'enrichment.writer',
        };
    }

    /** The model the plan binds to this job. Operators can run it elsewhere; this is the default. */
    public function suggestedModel(int $reviewTier = 1): string
    {
        return $this === self::FactReview && $reviewTier >= 2 ? 'claude-sonnet-5' : 'claude-haiku-4-5';
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_combine(
            array_map(fn (self $t): string => $t->value, self::cases()),
            array_map(fn (self $t): string => $t->label(), self::cases()),
        );
    }
}
