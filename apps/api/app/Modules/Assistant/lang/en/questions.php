<?php

return [
    // Shown before shoppers have asked anything about a product.
    'common' => [
        'What is this product good for?',
        'What else do I need with it?',
        'How do I install or use it?',
        'How do I take care of it?',
    ],
    // Shown before readers have asked anything about a guide. Summing it up comes first.
    'article' => [
        'Sum this guide up for me in a few words',
        'What is the most important thing here?',
        'Who is this guide for?',
        'What should I do after reading it?',
    ],
    // Built from what the scan found in this page itself, so the chips differ per article.
    'asked' => [
        'summary' => 'Sum this article up for me',
        'points' => ':count things worth knowing',
        'term' => 'What is :term?',
        'audience' => 'Who is this for?',
    ],
];
