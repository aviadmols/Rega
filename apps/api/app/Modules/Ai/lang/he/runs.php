<?php

return [
    'connected' => 'המפתח של :provider תקין. :count מודלים זמינים.',
    'failures' => [
        'invalid_key' => ':provider דחה את המפתח. בדקו שהעתקתם אותו במלואו ושהוא לא בוטל.',
        'permission_denied' => 'למפתח של :provider אין הרשאה לרשימת המודלים. בדקו את הרשאות המפתח או הפרויקט.',
        'rate_limited' => ':provider הגביל זמנית את הבקשות. נסו שוב בעוד דקה.',
        'provider_error' => ':provider החזיר שגיאה. ייתכן שהשירות לא זמין כרגע.',
        'unreachable' => 'אי אפשר להתחבר ל־:provider. בדקו את החיבור לרשת.',
    ],
];
