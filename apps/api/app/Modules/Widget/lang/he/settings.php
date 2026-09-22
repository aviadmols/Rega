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
    'popularity_min_count' => [
        'label' => 'מספר מינימלי להצגת הספירה',
        'description' => 'מספר ההוספות לסל או ההזמנות שמתחתיו המספר לא מוצג לגולשים. שתי הוספות לא מוכיחות כלום.',
    ],
    'whatsapp_number' => [
        'label' => 'מספר וואטסאפ של החנות',
        'description' => 'מספר בינלאומי בלי סימנים, למשל 972501234567. בלי מספר הרצועה לא מוצגת.',
    ],
    'whatsapp_title' => [
        'label' => 'המשפט שמופיע ברצועה',
        'description' => 'למשל: רוצה שנשלח לך סרטון וידאו של המוצר? ריק מציג את משפט ברירת המחדל.',
    ],
    'whatsapp_button' => [
        'label' => 'הכיתוב על הכפתור',
        'description' => 'למשל: לשיחה בוואטסאפ.',
    ],
    'whatsapp_message' => [
        'label' => 'ההודעה שנפתחת בוואטסאפ',
        'description' => 'הגולש שולח אותה לחנות. :product מוחלף בשם המוצר ו־:url בכתובת העמוד.',
    ],
    'whatsapp_offline_note' => [
        'label' => 'ההודעה מחוץ לשעות הפעילות',
        'description' => 'מוצגת מתחת לכפתור כשאין מענה עכשיו. ריק מציג את ברירת המחדל.',
    ],
    'whatsapp_hours' => [
        'label' => 'שעות פעילות, ראשון עד חמישי',
        'description' => 'בפורמט 09:00-18:00. ריק סוגר את היום.',
    ],
    'whatsapp_hours_friday' => [
        'label' => 'שעות פעילות בשישי',
        'description' => 'בפורמט 09:00-13:00. ריק סוגר את היום.',
    ],
    'whatsapp_hours_saturday' => [
        'label' => 'שעות פעילות בשבת',
        'description' => 'בפורמט 10:00-14:00. ריק סוגר את היום.',
    ],
    'whatsapp_timezone' => [
        'label' => 'אזור הזמן של החנות',
        'description' => 'לפי אזורי הזמן של IANA, למשל Asia/Jerusalem.',
    ],
    'whatsapp_when_offline' => [
        'label' => 'מחוץ לשעות הפעילות',
        'description' => 'להציג את הרצועה עם הודעה, או להסתיר אותה.',
        'options' => ['show' => 'להציג עם הודעה', 'hide' => 'להסתיר'],
    ],
];
