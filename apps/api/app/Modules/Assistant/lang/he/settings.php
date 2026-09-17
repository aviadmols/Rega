<?php

return [
    'answer_model' => [
        'label' => 'מודל שכותב את התשובות',
        'description' => 'מזהה מודל של OpenAI מהרשימה שבמפתח, למשל gpt-5.4-mini.',
    ],
    'scope_model' => [
        'label' => 'מודל שבודק שהשאלה על המוצר',
        'description' => 'מודל קטן וזול. שאלה שלא על המוצר לא מגיעה למודל הכותב.',
    ],
    'reasoning_effort' => [
        'label' => 'כמה המודל חושב לפני שעונה',
        'description' => 'יותר חשיבה נותנת תשובות זהירות יותר, אבל איטיות ויקרות יותר.',
        'options' => [
            'model_default' => 'ברירת המחדל של המודל',
            'minimal' => 'מינימלי',
            'low' => 'נמוך',
            'medium' => 'בינוני',
        ],
    ],
    'answer_input_usd_per_million' => [
        'label' => 'מחיר קלט של המודל הכותב, למיליון טוקנים',
        'description' => 'לפי המחירון של OpenAI. משמש להערכה לפני כל קריאה ולרישום העלות. עדיף להעריך גבוה.',
    ],
    'answer_output_usd_per_million' => [
        'label' => 'מחיר פלט של המודל הכותב, למיליון טוקנים',
        'description' => 'לפי המחירון של OpenAI. טוקני חשיבה נספרים כפלט.',
    ],
    'scope_input_usd_per_million' => [
        'label' => 'מחיר קלט של המודל הבודק, למיליון טוקנים',
        'description' => 'לפי המחירון של OpenAI.',
    ],
    'scope_output_usd_per_million' => [
        'label' => 'מחיר פלט של המודל הבודק, למיליון טוקנים',
        'description' => 'לפי המחירון של OpenAI.',
    ],
    'answer_max_output_tokens' => [
        'label' => 'תקרת טוקנים לתשובה',
        'description' => 'כולל טוקני חשיבה. נמוך מדי עלול להשאיר את התשובה ריקה.',
    ],
    'max_question_chars' => [
        'label' => 'אורך שאלה מקסימלי',
        'description' => 'שאלה ארוכה יותר נחתכת.',
    ],
    'max_text_chars' => [
        'label' => 'אורך תיאור המוצר שנשלח עם השאלה',
        'description' => 'התשובה נכתבת מהעובדות שנבדקו, מהנקודות החשובות ומהתיאור עד האורך הזה.',
    ],
    'questions_per_visitor_per_day' => [
        'label' => 'שאלות חדשות לגולש ביום',
        'description' => 'שאלה שכבר נענתה לא נספרת.',
    ],
    'questions_per_shop_per_day' => [
        'label' => 'שאלות חדשות לחנות ביום',
        'description' => 'מעבר לזה גולשים מקבלים הפניה לצוות החנות עד מחר.',
    ],
    'asks_per_minute' => [
        'label' => 'בקשות שאלה בדקה, לכל כתובת',
        'description' => 'הגנה מהצפה, גם לשאלות שנענות מהזיכרון.',
    ],
    'suggested_questions' => [
        'label' => 'שאלות מוצעות בחלונית',
        'description' => 'קודם השאלות שנשאלו הכי הרבה על המוצר, ואז שאלות כלליות.',
    ],
];
