# פריסה ל־Railway

> **סטטוס:** ההגדרות נכתבו ונבדקו מקומית: קובצי ה־JSON תקינים, ה־caches של production נבנים, ו־CI בונה את ה־image. עדיין לא נפרסו לפרויקט Railway אמיתי. בפריסה הראשונה צריך לאמת את הנקודות שמסומנות "לאמת".

## שירותים

פרויקט אחד, סביבות `staging` ו־`production`. כל שלושת שירותי האפליקציה בונים את אותו image מ־`apps/api`.

| שירות | Root directory | Config file | משתנה APP_ROLE |
|---|---|---|---|
| api | `apps/api` | `/infra/railway/web.json` | `web` |
| worker | `apps/api` | `/infra/railway/background.json` | `worker` |
| scheduler | `apps/api` | `/infra/railway/background.json` | `scheduler` |
| Postgres | תבנית Railway | | |
| Redis | תבנית Railway | | |

**לאמת:** שהתבנית של Postgres ב־Railway מאפשרת `CREATE EXTENSION vector`. אם לא, להשתמש ב־image של pgvector כשירות, או ב־Postgres חיצוני. CI כבר בודק את ההרחבה על `pgvector/pgvector:pg17`.

**לאמת:** ש־`dockerfilePath` בקובצי ה־config נקרא יחסית ל־root directory של השירות.

## משתנים משותפים

```
APP_NAME="Shopping Assistant"
APP_ENV=production
APP_KEY=base64:...            # php artisan key:generate --show
APP_URL=https://api.<domain>
APP_LOCALE=he
DB_CONNECTION=pgsql
DB_URL=${{Postgres.DATABASE_URL}}
REDIS_URL=${{Redis.REDIS_URL}}
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
LOG_CHANNEL=stderr
```

רק בשירות `api`:

```
APP_ROLE=web
RUN_MIGRATIONS=true
```

## פריסה ראשונה

1. ליצור את חמשת השירותים ולהגדיר משתנים.
2. לפרוס את `api` ולחכות ל־healthcheck על `/up`.
3. ליצור מפעיל ראשון מתוך השירות:

```sh
railway run --service api php artisan admin:operator you@example.com --no-interaction
```

הסיסמה שנוצרה מודפסת פעם אחת.

4. לפרוס את `worker` ו־`scheduler`.

## Cloudflare

- DNS של `api.<domain>` בפרוקסי מול הדומיין ש־Railway נותן.
- מהשלב שבו ה־widget וה־bank קיימים: cache rules ארוכים לנתיבים `/w/*` ו־`/bank/*`. יש גרסה בנתיב, ולכן אין צורך ב־purge.
- להימנע מהמילים upsell ו־assistant בשם הדומיין הציבורי, כי חוסמי פרסומות מסננים אותן.

## החזרה לאחור

ב־Railway: Deployments, בחירת הפריסה הקודמת, Redeploy. migration הרסנית דורשת migration הפוכה ולא rollback של image.
