<?php

return [
    'steps_per_minute' => [
        'label' => 'Steps a minute from one site',
        'description' => 'A rate limit on the lead form, so nobody fills it with a script.',
    ],
    'retention_days' => [
        'label' => 'How long a lead is kept',
        'description' => 'After this a lead is deleted. A single lead can be deleted at any time too.',
    ],
];
