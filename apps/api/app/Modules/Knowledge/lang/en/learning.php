<?php

return [
    'title' => 'Learning',
    'help' => 'How the system improves over time and from shop to shop, what it learned this week, and whether any of it helped.',

    'stages_title' => 'The stages',
    'stages_help' => 'Each stage, what it does and why, and when it is not running, exactly what is missing.',
    'state' => [
        'running' => 'running',
        'waiting' => 'waiting',
        'planned' => 'planned',
    ],
    'states' => [
        'every_night' => 'Runs every night.',
        'every_week' => 'Runs weekly, on Sunday morning.',
        'share' => ':n% of shoppers are shown the fixed order and act as the control group.',
        'needs_traffic' => 'Needs at least :n exposures on each side before anything can be said.',
        'needs_shops' => 'Needs :n shops of one trade. There are :have.',
        'planned' => 'Not built yet.',
    ],
    'stages' => [
        'reading' => [
            'title' => 'Nightly reading in code',
            'body' => 'Everything code can learn about a shop — brand, type, sizes, promises, points from articles, superlatives and relations — is read again every night. It skips what has not changed by the fingerprint of its text, so it costs nothing.',
        ],
        'model_reading' => [
            'title' => 'Model reading, a fixed number a night',
            'body' => 'Products code could not work out on its own go to a model, a fixed number each night. Every answer is checked in code before one fact is written. It stops at the monthly spend cap and carries on tomorrow from where it stopped.',
        ],
        'audit' => [
            'title' => 'Auditing the reading',
            'body' => 'Once a week a model reads a few articles and says what code missed. A second model reviews the proposal, and its effect is measured on articles already read. A proposal that survives all of that publishes itself — at most one version a week, and always reversible.',
        ],
        'holdout' => [
            'title' => 'The control group',
            'body' => 'Some shoppers are shown the fixed order rather than the learned one. Which side a visitor is on comes from their own id, so the same person is always on the same side. What they do is kept out of the learning; otherwise it would be comparing a thing to itself.',
        ],
        'measuring' => [
            'title' => 'Did it help',
            'body' => 'Once a week both sides are counted the same way: opened after being seen, clicked after being opened. "Too early" is a legitimate answer, and for a young shop it is usually the right one.',
        ],
        'promotion' => [
            'title' => 'Learning between shops of a trade',
            'body' => 'A word several shops of a trade arrived at separately joins the trade\'s template, and a new shop inherits it at once. One shop finding something is a special case; several finding it is a pattern.',
        ],
        'priors' => [
            'title' => 'A starting point for a new shop',
            'body' => 'A shop that opened today has watched nobody, so it is arranged by what works for other shops of its trade. The moment it has enough of its own, its own wins.',
        ],
        'questions' => [
            'title' => 'Routing questions between shops',
            'body' => 'Learning that a question about warranty is answered from a warranty fact, and applying that in every shop with its own facts — so code answers with no model at all. Needs several hundred answered questions.',
        ],
        'session' => [
            'title' => 'Fitting the visit',
            'body' => 'What a shopper opened and dismissed in this visit arranging the panels for them. Dismissing is a strong signal nothing learns from today.',
        ],
    ],

    'week_title' => 'What was learned this week',
    'week_help' => 'The difference between today\'s snapshot and the one from a week ago.',
    'week_none' => 'No snapshot has been recorded yet.',
    'first_week' => 'the first week — nothing to compare against yet',
    'gained' => 'Gained: :code in code · :model by model · :articles articles',
    'following_nothing' => 'no signal strong enough',
    'versus' => 'against',
    'thin' => 'too little to judge by',
    'columns' => [
        'shop' => 'Shop',
        'known' => 'Known',
        'gained' => 'Gained',
        'following' => 'Learning from',
        'gaps' => 'Gaps',
    ],

    'measured_title' => 'Did it help',
    'measured_help' => 'The learned order against the fixed one, over the same window.',
    'measured_none' => 'Not measured yet. The measurement runs weekly.',

    'trades_title' => 'What crosses between shops',
    'trades_help' => 'What each trade has learned, and what a new shop of it gets on its first day.',
    'trade_shops' => ':n shops in this trade',
    'trade_words' => ':n words in the template, version :version',
    'trade_nothing' => 'This trade has not learned anything yet.',

    'privacy_title' => 'What never crosses',
    'privacy' => 'Only rules and aggregate rates cross between shops: a word that marks a conclusion, and how often a panel is opened across a trade. Products, prices, questions shoppers asked and anything to do with a person never do. There is no route by which they could — the only things read from a shop are the list of words it published and its rates.',
];
