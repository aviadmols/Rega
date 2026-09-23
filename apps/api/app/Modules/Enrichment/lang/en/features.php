<?php

return [
    'auto_approve' => [
        'label' => 'Approve checked facts automatically',
        'description' => 'When code and a model agree, or a reviewer model confirms, the fact is approved. When off, every fact waits for a person.',
    ],
    'weekly_audit' => [
        'label' => 'Weekly audit of article reading',
        'description' => 'Once a week a model checks a few articles code has read, proposes how to read better, and a second model reviews the proposal. Nothing changes without approval on the Scan improvements page.',
    ],
    'nightly' => [
        'label' => 'Nightly reading',
        'description' => 'Every night, after the sync, code reads what changed in the shop again: products, promises, articles, superlatives and relations. No model, no cost.',
    ],
];
