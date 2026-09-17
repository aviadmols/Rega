<?php

return [
    'singular' => 'חיבור חנות',
    'plural' => 'חיבור חנויות',
    'statuses' => [
        'untested' => 'לא נבדק',
        'connected' => 'מחובר',
        'failed' => 'נכשל',
    ],
    'sections' => [
        'connection' => 'פרטי החיבור',
        'connection_help' => 'Rega קוראת את הקטלוג דרך תוסף Rega שמותקן בחנות. הטוקן נוצר בתוסף ונשמר כאן מוצפן.',
        'site' => 'מצב האתר',
    ],
    'fields' => [
        'shop' => 'חנות',
        'platform' => 'פלטפורמה',
        'site_url' => 'כתובת האתר',
        'site_url_help' => 'הכתובת הראשית של האתר, למשל https://store.co.il',
        'access_token' => 'טוקן התוסף',
        'access_token_help' => 'ב־WordPress: WooCommerce > Rega > יצירת טוקן. מתחיל ב־rgt_.',
        'access_token_keep' => 'ריק ישאיר את הטוקן הקיים.',
        'status' => 'מצב',
        'last_checked_at' => 'בדיקה אחרונה',
        'never' => 'לא נבדק',
        'last_error' => 'שגיאה אחרונה',
        'token' => 'טוקן',
        'site_name' => 'שם האתר',
        'plugin_version' => 'גרסת התוסף',
        'published_products' => 'מוצרים מפורסמים',
        'variations' => 'וריאנטים',
        'categories' => 'קטגוריות',
        'locale' => 'שפת האתר',
    ],
    'errors' => [
        'invalid_token' => 'טוקן לא תקין',
        'locked_out' => 'חסום זמנית',
        'plugin_missing' => 'התוסף לא נמצא',
        'woocommerce_inactive' => 'WooCommerce לא פעיל',
        'http_error' => 'שגיאת שרת',
        'unexpected_response' => 'תשובה לא צפויה',
        'unreachable' => 'האתר לא זמין',
    ],
    'actions' => [
        'test' => 'בדיקת חיבור',
    ],
    'notifications' => [
        'connected' => 'החנות מחוברת',
        'failed' => 'החיבור נכשל',
        'view_run' => 'לפרטי הפעולה',
    ],
    'empty' => [
        'heading' => 'אין עדיין חנויות מחוברות',
        'description' => 'התקינו את תוסף Rega בחנות, צרו טוקן והוסיפו כאן חיבור.',
    ],
];
