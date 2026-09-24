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
        'catalog_sync' => 'סנכרון הקטלוג מהחנות',
        'enrichment_read_in_code' => 'קריאת המוצרים בקוד',
        'enrichment_read_promises' => 'קריאת ההבטחות',
        'enrichment_read_content' => 'קריאת המאמרים',
        'enrichment_compute_rankings' => 'חישוב סופרלטיבים',
        'enrichment_compute_relations' => 'חישוב קשרים בין מוצרים',
        'enrichment_audit_content' => 'ביקורת על קריאת המאמרים',
        'analytics_compute_scores' => 'חישוב הציונים מהתנהגות הגולשים',
        'analytics_compute_popularity' => 'חישוב פופולריות',
    ],
    'verdict_lines' => [
        'helped' => 'הסדר הנלמד עובד טוב יותר מהסדר הקבוע',
        'hurt' => 'הסדר הנלמד עובד פחות טוב מהסדר הקבוע',
        'no_difference' => 'אין הבדל בין הסדר הנלמד לקבוע',
        'too_early' => 'מוקדם מדי לומר — צריך עוד נתונים',
        'no_control' => 'אין קבוצת ביקורת, ולכן אין מול מה להשוות',
    ],
];
