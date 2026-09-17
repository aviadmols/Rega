<?php

$positions = [
    'after' => 'אחרי האלמנט',
    'before' => 'לפני האלמנט',
    'prepend' => 'בתוך האלמנט, בהתחלה',
    'append' => 'בתוך האלמנט, בסוף',
];

return [
    'product_selector' => [
        'label' => 'איפה להציג בעמוד מוצר (סלקטור CSS)',
        'description' => 'CLASS או כל סלקטור CSS מתבנית האתר, למשל .product-summary או form.cart. הרכיב ממוקם לפי האלמנט הראשון שנמצא.',
    ],
    'product_position' => [
        'label' => 'מיקום בעמוד מוצר',
        'description' => 'איפה למקם את הרכיב ביחס לאלמנט.',
        'options' => $positions,
    ],
    'content_selector' => [
        'label' => 'איפה להציג במאמר (סלקטור CSS)',
        'description' => 'CLASS או סלקטור CSS בתבנית המאמר, למשל .entry-content.',
    ],
    'content_position' => [
        'label' => 'מיקום במאמר',
        'description' => 'איפה למקם את הרכיב ביחס לאלמנט.',
        'options' => $positions,
    ],
    'floating_fallback' => [
        'label' => 'כפתור צף כשהאלמנט לא נמצא',
        'description' => 'כשאף אלמנט לא מתאים לסלקטור, מוצג כפתור קטן בתחתית המסך במקום כלום.',
    ],
    'common_highlight_products' => [
        'label' => 'נקודה חשובה שחוזרת במוצרים רבים',
        'description' => 'נקודה חשובה שהציטוט שלה מופיע אצל לפחות כמה מוצרים כאלה, כמו הערה קבועה על סטייה במידות, מוצגת אחרונה ולא נבחרת למשפט המפתח.',
    ],
    'max_products' => [
        'label' => 'מוצרים בכל רשימה',
        'description' => 'מספר המוצרים המרבי ב״מתאים לקנות יחד״ וליד מאמר.',
    ],
    'page_cache_seconds' => [
        'label' => 'שמירת תוכן עמוד',
        'description' => 'כמה זמן תוכן הרכיב של עמוד נשמר לפני שנבנה מחדש. מחירים ומלאי תמיד עדכניים.',
    ],
    'page_requests_per_minute' => [
        'label' => 'בקשות תוכן עמוד בדקה, לכל כתובת גולש',
        'description' => 'מעבר לזה הבקשות נדחות עד סוף הדקה.',
    ],
];
