<?php

return [
    'max_products' => [
        'label' => 'Maximum products per shop',
        'description' => 'A sync stops reading at this number and says so. Nothing is marked removed after a stopped sync.',
    ],
    'max_content_items' => [
        'label' => 'Maximum articles and guides per shop',
        'description' => 'Content beyond this number is not read.',
    ],
];
