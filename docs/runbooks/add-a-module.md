# הוספת מודול

```sh
cd apps/api
php artisan make:module DisplayModels --requires=Tenancy
```

נוצר שלד שעובר את כל הבדיקות: `module.json`, ספק שירות, תרגומים בעברית ובאנגלית, תיקיות, ובדיקת עשן.

## מה להוסיף, ואיפה

| רוצה להוסיף | איפה | מה קורה אוטומטית |
|---|---|---|
| טבלה | `Database/Migrations` | נטען ב־migrate |
| מתג לחנות | `module.json` בשדה `features`, ותווית ב־`lang/*/features.php` | מופיע במסך ההגדרות |
| תקרה או סף | `module.json` בשדה `settings` עם `type`, `min`, `max`, `default`, ותווית ב־`lang/*/settings.php` | מופיע במסך ההגדרות, נאכף בכל שמירה |
| endpoint ל־API | `routes/api.php` | מקבל קידומת `/api/v1` |
| מסך למפעיל | `Filament/Operator/Resources` או `Pages` | מופיע בפאנל המפעיל |
| מסך לסוחר | `Filament/Merchant/Resources` או `Pages` | מופיע בפאנל הסוחר, מסונן לחנות |
| פקודת artisan | `Console` ורישום ב־`moduleCommands()` | |
| נתוני חנות | מודל עם `BelongsToTenant` ועמודת `shop_id` | מסונן לחנות הנוכחית |

## כללים

- ייבוא ממודול אחר: רק אם הוא ב־`requires`, ורק מ־`Contracts`, `Models`, `Enums`, `Events`.
- כל מחרוזת בממשק בשתי השפות.
- לוגיקה ב־`Actions`, מחלקה עם מתודה אחת.
- דגל חדש כבוי כברירת מחדל, אלא אם יש סיבה טובה אחרת.
- החלטה ארכיטקטונית מקבלת ADR ב־`docs/ADR`.

## לפני שמסיימים

```sh
composer check
```
