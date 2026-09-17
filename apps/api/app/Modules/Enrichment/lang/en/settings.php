<?php

return [
    'max_requests_per_task_file' => [
        'label' => 'Maximum requests in one task file',
        'description' => 'Larger jobs are split: create another file when the first is done.',
    ],
    'max_agent_text_chars' => [
        'label' => 'Product text sent to a model',
        'description' => 'Code shortens each product to this many characters, keeping lines with numbers and specs first. Measurements are found before shortening is applied to prose.',
    ],
    'max_products_per_article' => [
        'label' => 'Products shown next to an article',
        'description' => 'The most products chosen for one article or guide. Linked products come first, then products from the categories a checker approved.',
    ],
    'min_set_size' => [
        'label' => 'Smallest set for a superlative',
        'description' => 'No "lightest" or "cheapest" among fewer comparable products in stock than this.',
    ],
    'max_upload_kilobytes' => [
        'label' => 'Largest results file',
        'description' => 'Uploads above this size are refused.',
    ],
];
