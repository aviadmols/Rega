<?php

return [
    'answer_model' => [
        'label' => 'Model that writes answers',
        'description' => 'An OpenAI model ID from the key\'s list, such as gpt-5.4-mini.',
    ],
    'scope_model' => [
        'label' => 'Model that checks a question is about the product',
        'description' => 'A small, cheap model. A question that is not about the product never reaches the writing model.',
    ],
    'reasoning_effort' => [
        'label' => 'How much the model thinks before answering',
        'description' => 'More thinking gives more careful answers, but slower and more expensive ones.',
        'options' => [
            'model_default' => 'The model\'s default',
            'minimal' => 'Minimal',
            'low' => 'Low',
            'medium' => 'Medium',
        ],
    ],
    'answer_input_usd_per_million' => [
        'label' => 'Writing model input price per million tokens',
        'description' => 'From OpenAI\'s price list. Used for the estimate before each call and for the recorded cost. Better too high than too low.',
    ],
    'answer_output_usd_per_million' => [
        'label' => 'Writing model output price per million tokens',
        'description' => 'From OpenAI\'s price list. Reasoning tokens count as output.',
    ],
    'scope_input_usd_per_million' => [
        'label' => 'Checking model input price per million tokens',
        'description' => 'From OpenAI\'s price list.',
    ],
    'scope_output_usd_per_million' => [
        'label' => 'Checking model output price per million tokens',
        'description' => 'From OpenAI\'s price list.',
    ],
    'answer_max_output_tokens' => [
        'label' => 'Answer token cap',
        'description' => 'Includes reasoning tokens. Too low can leave the answer empty.',
    ],
    'max_question_chars' => [
        'label' => 'Longest question',
        'description' => 'Longer questions are cut.',
    ],
    'max_text_chars' => [
        'label' => 'Product description sent with a question',
        'description' => 'Answers are written from checked facts, highlights and the description up to this length.',
    ],
    'questions_per_visitor_per_day' => [
        'label' => 'New questions per visitor per day',
        'description' => 'A question answered before does not count.',
    ],
    'questions_per_shop_per_day' => [
        'label' => 'New questions per shop per day',
        'description' => 'Past this, shoppers are pointed to the store team until tomorrow.',
    ],
    'asks_per_minute' => [
        'label' => 'Question requests per minute, per address',
        'description' => 'Flood protection, including questions answered from memory.',
    ],
    'suggested_questions' => [
        'label' => 'Suggested questions in the box',
        'description' => 'The questions asked most about the product first, then common ones.',
    ],
];
