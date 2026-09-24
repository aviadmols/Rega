<?php

return [
    'signals' => [
        'exposure' => 'Widget seen',
        'open' => 'Widget opened',
        'click' => 'Item clicked',
        'dismiss' => 'Dismissed',
        'add_to_cart' => 'Added to cart',
        'order' => 'Order',
    ],
    'verdicts' => [
        'alive' => 'alive',
        'weak' => 'weak',
        'missing' => 'missing',
    ],
    'gaps' => [
        'products_without_facts' => 'Products with no fact at all',
        'articles_without_points' => 'Articles no points were found in',
        'no_pages_shared' => 'The shop shares no content pages, so there are no shop-level promises',
        'questions_without_an_answer' => 'Questions answered "I have no information" — the site does not say',
        'no_relations' => 'No relations between products have been computed',
    ],
    'steps' => [
        'catalog_sync' => 'Catalogue sync from the shop',
        'enrichment_read_in_code' => 'Reading products in code',
        'enrichment_read_promises' => 'Reading promises',
        'enrichment_read_content' => 'Reading articles',
        'enrichment_compute_rankings' => 'Computing superlatives',
        'enrichment_compute_relations' => 'Computing relations between products',
        'enrichment_audit_content' => 'Auditing how articles are read',
        'analytics_compute_scores' => 'Computing scores from shopper behaviour',
        'analytics_compute_popularity' => 'Computing popularity',
    ],
    'verdict_lines' => [
        'helped' => 'the learned order does better than the fixed one',
        'hurt' => 'the learned order does worse than the fixed one',
        'no_difference' => 'no difference between the learned and the fixed order',
        'too_early' => 'too early to say — not enough yet',
        'no_control' => 'nobody is held out, so there is nothing to compare against',
    ],
];
