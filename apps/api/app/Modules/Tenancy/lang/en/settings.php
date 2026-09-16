<?php

return [
    'max_active_api_keys' => [
        'label' => 'Maximum active API keys',
        'description' => 'How many active keys a shop can hold at the same time.',
    ],
    'api_requests_per_minute' => [
        'label' => 'API requests per minute, per key',
        'description' => 'Beyond this the shop gets a rate_limited error until the minute ends.',
    ],
];
