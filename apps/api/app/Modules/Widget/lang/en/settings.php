<?php

$positions = [
    'after' => 'After the element',
    'before' => 'Before the element',
    'prepend' => 'Inside the element, at the start',
    'append' => 'Inside the element, at the end',
];

return [
    'product_selector' => [
        'label' => 'Where to show it on product pages (CSS selector)',
        'description' => 'A class or any CSS selector from the store theme, such as .product-summary or form.cart. The first matching element is used.',
    ],
    'product_position' => [
        'label' => 'Position on product pages',
        'description' => 'Where to place the widget relative to that element.',
        'options' => $positions,
    ],
    'content_selector' => [
        'label' => 'Where to show it on articles (CSS selector)',
        'description' => 'A class or CSS selector in the article template, such as .entry-content.',
    ],
    'content_position' => [
        'label' => 'Position on articles',
        'description' => 'Where to place the widget relative to that element.',
        'options' => $positions,
    ],
    'floating_fallback' => [
        'label' => 'Float at the bottom when the element is missing',
        'description' => 'When no element matches, show a small button at the bottom of the screen instead of nothing.',
    ],
    'common_highlight_products' => [
        'label' => 'Highlight repeated across many products',
        'description' => 'A highlight whose quote appears on at least this many products, such as a standing note about size tolerance, is shown last and never becomes the key sentence.',
    ],
    'max_products' => [
        'label' => 'Products in each list',
        'description' => 'Most products shown under "goes well with it" and next to an article.',
    ],
    'page_cache_seconds' => [
        'label' => 'Keep page content for',
        'description' => 'How long the widget content for a page is kept before it is built again. Prices and stock are always live.',
    ],
    'page_requests_per_minute' => [
        'label' => 'Page content requests per minute, per visitor address',
        'description' => 'Beyond this, requests are refused until the minute ends.',
    ],
];
