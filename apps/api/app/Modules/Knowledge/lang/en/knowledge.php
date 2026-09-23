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
];
