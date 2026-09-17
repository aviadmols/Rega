<?php

return [
    'attribution_days' => [
        'label' => 'Attribution window',
        'description' => 'An order counts toward Rega when the visitor used the widget within this many days before ordering.',
    ],
    'beacons_per_minute' => [
        'label' => 'Event batches per minute, per visitor address',
        'description' => 'Beyond this, batches are refused until the minute ends.',
    ],
    'retention_days' => [
        'label' => 'Keep events for',
        'description' => 'Older events are deleted every night. Orders are kept.',
    ],
];
