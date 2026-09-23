<?php

return [
    'signals' => [
        'exposure' => 'הרכיב נראה',
        'open' => 'הרכיב נפתח',
        'click' => 'לחיצה על פריט',
        'dismiss' => 'סגירה',
        'add_to_cart' => 'הוספה לסל',
        'order' => 'הזמנה',
    ],
    'verdicts' => [
        'alive' => 'חי',
        'weak' => 'חלש',
        'missing' => 'חסר',
    ],
    'gaps' => [
        'products_without_facts' => 'מוצרים שאין עליהם אף עובדה',
        'articles_without_points' => 'מאמרים שלא נמצאו בהם נקודות',
        'no_pages_shared' => 'החנות לא משתפת עמודי תוכן, ולכן אין הבטחות ברמת החנות',
        'questions_without_an_answer' => 'שאלות שנענו ״אין לי מידע״ — המידע חסר באתר',
        'no_relations' => 'לא חושבו קשרים בין מוצרים',
    ],
    'steps' => [
        'catalog.sync' => 'סנכרון הקטלוג מהחנות',
        'enrichment.read_in_code' => 'קריאת המוצרים בקוד',
        'enrichment.read_promises' => 'קריאת ההבטחות',
        'enrichment.read_content' => 'קריאת המאמרים',
        'enrichment.compute_rankings' => 'חישוב סופרלטיבים',
        'enrichment.compute_relations' => 'חישוב קשרים בין מוצרים',
        'enrichment.audit_content' => 'ביקורת על קריאת המאמרים',
        'analytics.compute_scores' => 'חישוב הציונים מהתנהגות הגולשים',
        'analytics.compute_popularity' => 'חישוב פופולריות',
    ],
];
