<?php

namespace App\Modules\Assistant\Contracts;

/**
 * What this page is worth being asked, in the reader's own language.
 *
 * Two screens need the same list and must not disagree about it: the question box, which offers
 * them as chips once it is open, and the closed widget, which puts one of them in front of a
 * shopper who has not opened anything yet.
 */
interface SuggestsQuestions
{
    /**
     * @param  string  $pageId  the product's or article's internal id
     * @return list<string> best first, already worded
     */
    public function for(string $pageId, bool $isArticle, string $locale, int $limit): array;
}
